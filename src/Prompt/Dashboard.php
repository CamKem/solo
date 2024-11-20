<?php
/**
 * @author Aaron Francis <aarondfrancis@gmail.com>
 * @link https://aaronfrancis.com
 * @link https://twitter.com/aarondfrancis
 */

namespace AaronFrancis\Solo\Prompt;

use AaronFrancis\Solo\Commands\Command;
use AaronFrancis\Solo\Facades\Solo;
use AaronFrancis\Solo\Hotkeys\DefaultHotkeys;
use AaronFrancis\Solo\Hotkeys\Hotkey;
use AaronFrancis\Solo\Hotkeys\KeyHandler;
use AaronFrancis\Solo\Hotkeys\VimHotkeys;
use AaronFrancis\Solo\Support\Frames;
use Chewie\Concerns\CreatesAnAltScreen;
use Chewie\Concerns\Loops;
use Chewie\Concerns\RegistersRenderers;
use Chewie\Concerns\SetsUpAndResets;
use Chewie\Input\KeyPressListener;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Collection;
use Illuminate\Support\Sleep;
use Laravel\Prompts\Key;
use Laravel\Prompts\Prompt;
use Laravel\Prompts\Terminal;

class Dashboard extends Prompt
{
    use CreatesAnAltScreen, Loops, RegistersRenderers, SetsUpAndResets;

    /**
     * @var array<Command>
     */
    public array $commands = [];

    public int $selectedCommand = 0;

    public ?int $lastSelectedCommand = null;

    public int $width;

    public int $height;

    public Frames $frames;

    public KeyPressListener $listener;

    public static function start(): void
    {
        (new static)->run();
    }

    public function __construct()
    {
        $this->registerRenderer(Solo::getRenderer());
        $this->createAltScreen();

        $this->listener = KeyPressListener::for($this);

        [$this->width, $this->height] = $this->getDimensions();

        pcntl_signal(SIGWINCH, [$this, 'handleResize']);
        pcntl_signal(SIGINT, [$this, 'quit']);
        pcntl_signal(SIGTERM, [$this, 'quit']);

        $this->frames = new Frames;

        $this->commands = collect(Solo::commands())
            ->tap(function (Collection $commands) {
                // If they haven't added any commands, just show the About command.
                if ($commands->isEmpty()) {
                    $commands->push(Command::make('About', 'php artisan solo:about'));
                }
            })
            ->each(function (Command $command) {
                $command->setDimensions($this->width, $this->height);
                $command->autostart();
            })
            ->all();

        $this->registerLoopables(...$this->commands);
    }

    public function run(): void
    {
        $this->setup($this->showDashboard(...));
    }

    public function currentCommand(): Command
    {
        return $this->commands[$this->selectedCommand];
    }

    public function getDimensions(): array
    {
        return [
            $this->terminal()->cols(),
            $this->terminal()->lines()
        ];
    }

    public function handleResize(): false
    {
        // Clear out the ENV, otherwise it just returns cached values.
        putenv('COLUMNS');
        putenv('LINES');

        $terminal = new Terminal;
        $terminal->initDimensions();

        // Put them back in, in case anyone else needs them.
        putenv('COLUMNS=' . $terminal->cols());
        putenv('LINES=' . $terminal->lines());

        [$width, $height] = $this->getDimensions();

        if ($width !== $this->width || $height !== $this->height) {
            $this->width = $width;
            $this->height = $height;

            collect($this->commands)->each->setDimensions($this->width, $this->height);
        }

        return false;
    }

    public function rebindHotkeys()
    {
        $this->listener->clearExisting();

        collect(Solo::hotkeys())
            ->merge($this->currentCommand()->allHotkeys())
            ->each(function (Hotkey $hotkey) {
                $hotkey->init($this->currentCommand(), $this);
                $this->listener->on($hotkey->keys, $hotkey->handle(...));
            });
    }

    public function enterInteractiveMode()
    {
        if ($this->currentCommand()->processStopped()) {
            $this->currentCommand()->restart();
        }

        $this->currentCommand()->setMode(Command::MODE_INTERACTIVE);
    }

    public function exitInteractiveMode()
    {
        $this->currentCommand()->setMode(Command::MODE_PASSIVE);
    }

    public function nextTab()
    {
        $this->currentCommand()->blur();
        $this->selectedCommand = ($this->selectedCommand + 1) % count($this->commands);
        $this->currentCommand()->focus();
    }

    public function previousTab()
    {
        $this->currentCommand()->blur();
        $this->selectedCommand = ($this->selectedCommand - 1 + count($this->commands)) % count($this->commands);
        $this->currentCommand()->focus();
    }

    protected function showDashboard(): void
    {
        $this->currentCommand()->focus($this);

        $this->loop($this->renderSingleFrame(...), 25_000);
    }

    protected function renderSingleFrame()
    {
        if ($this->lastSelectedCommand !== $this->selectedCommand) {
            $this->lastSelectedCommand = $this->selectedCommand;
            $this->rebindHotkeys();
        }

        $this->currentCommand()->catchUpScroll();

        $this->render();

        $this->currentCommand()->isInteractive() ? $this->handleInteractiveInput() : $this->listener->once();

        $this->frames->next();
    }

    protected function handleInteractiveInput()
    {
        $read = [STDIN];
        $write = null;
        $except = null;

        if ($this->currentCommand()->processStopped()) {
            $this->exitInteractiveMode();
            return;
        }

        // Shorten the wait time since we're expecting keystrokes.
        if (stream_select($read, $write, $except, 0, 5_000) === 1) {
            $key = fread(STDIN, 10);

            // Exit interactive mode without stopping the underlying process.
            if ($key === "\x18") {
                $this->exitInteractiveMode();
                return;
            }

            $this->currentCommand()->sendInput($key);
        }
    }

    public function quit(): void
    {
        foreach ($this->commands as $command) {
            /** @var Command $command */

            // This handles stubborn processes, so we all
            // we have to do is call it and wait.
            $command->stop();
        }

        while (true) {
            $any = array_reduce($this->commands, function ($carry, $command) {
                return $carry || $command->processRunning();
            }, false);

            if ($any) {
                Sleep::for(100)->milliseconds();
            } else {
                break;
            }
        }

        $this->terminal()->exit();
    }

    public function loopWithListener(KeyPressListener $listener, $cb, int $frameDuration = 100_000): void
    {
        // Call immediately before we start looping.
        $cb($this);

        $lastTick = microtime(true);

        while (true) {
            $read = [STDIN];
            $write = [];
            $except = [];

            // Use stream_select to implement the sleep, but also respond immediately to key presses
            $changed = stream_select($read, $write, $except, 0, $frameDuration);

            if ($changed === false) {
                echo "An error occurred while waiting for input.\n";
                exit;
            }

            // A key was pressed, so execute this listener.
            if ($changed > 0) {
                $listener->once();
            }

            // Calculate the time elapsed since the last tick
            $currentTime = microtime(true);

            // Convert seconds to microseconds
            $elapsedMicroseconds = ($currentTime - $lastTick) * 1e6;

            // Respond to key presses immediately
            $continue = $cb($this);

            if ($continue === false) {
                break;
            }

            // Only tick if it's been greater than minSleep microseconds.
            if ($elapsedMicroseconds < $frameDuration) {
                continue;
            }

            $lastTick = $currentTime;

            foreach ($this->loopables as $component) {
                $component->tick();
            }
        }
    }

    public function value(): mixed
    {
        return null;
    }
}

