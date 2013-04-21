<?php

namespace Arara\Process;

class Item
{

    const STATUS_SUCESS = 2;
    const STATUS_ERROR = 4;
    const STATUS_FAIL = 6;

    private $userId;
    private $groupId;
    private $pid;
    private $ipc;

    private $callback;

    public function __construct($callback, Ipc\Ipc $ipc = null, $userId = null, $groupId = null)
    {
        $userId = $userId ?: posix_getuid();
        $groupId = $groupId ?: posix_getgid();

        if (!is_callable($callback)) {
            $message = 'Callback given is not a valid callable';
            throw new \InvalidArgumentException($message);

        } elseif (false === posix_getpwuid($userId)) {
            $message = sprintf('The given UID "%s" is not valid', $userId);
            throw new \InvalidArgumentException($message);

        } elseif (false === posix_getgrgid($groupId)) {
            $message = sprintf('The given GID "%s" is not valid', $groupId);
            throw new \InvalidArgumentException($message);
        }

        $ipc = $ipc ?: new Ipc\SharedMemory();
        $ipc->save('__running', false);

        $this->ipc = $ipc;
        $this->userId = $userId;
        $this->groupId = $groupId;
        $this->callback = $callback;
    }

    public function start(SignalHandler $signalHandler)
    {
        $pid = @pcntl_fork();

        if ($pid === -1) {
            $message = 'Unable to fork process';
            throw new \RuntimeException($message);

        } elseif ($pid > 0) {

            if (null !== $this->pid) {
                $message = 'Process already forked';
                throw new \UnexpectedValueException($message);
            }

            $this->pid = $pid;
            $this->getIpc()->save('__running', true);

        } elseif ($pid === 0) {

            // We are in the child process
            posix_setgid($this->getGroupId());
            posix_setuid($this->getUserId());

            $userId = posix_getuid();
            $groupId = posix_getgid();
            if ($userId != $this->getUserId() || $groupId != $this->getGroupId()) {
                $format = 'Unable to fork process as "%d:%d". "%d:%d" given';
                $message = sprintf($format, $this->getUserId(), $this->getGroupId(), $userId, $groupId);
                throw new \RuntimeException($message);
            }

            // Custom error hanlder
            set_error_handler(
                function ($severity, $message, $filename, $line) {
                    throw new \ErrorException(
                        $message,
                        0,
                        $severity,
                        $filename,
                        $line
                    );
                }
            );

            ob_start();

            try {

                $result = call_user_func($this->callback);
                $status = self::STATUS_SUCESS;
                $code   = 0;

            } catch (\ErrorException $exception) {

                $result = $exception->getMessage();
                $status = self::STATUS_FAIL;
                $code   = $exception->getSeverity() ?: 255;

            } catch (\Exception $exception) {

                $result = $exception->getMessage();
                $status = self::STATUS_ERROR;
                $code   = $exception->getCode() ?: 254;

            }

            restore_error_handler();

            $this->getIpc()->save('__result', $result);
            $this->getIpc()->save('__output', ob_get_clean());
            $this->getIpc()->save('__status', $status);
            $this->getIpc()->save('__running', false);

            $signalHandler->quit($code);
        }
    }

    public function stop()
    {
        $return = posix_kill($this->getPid(), SIGKILL);
        $this->getIpc()->destroy();

        return $return;
    }

    public function wait()
    {
        pcntl_waitpid($this->getPid(), $status);

        return $status;
    }

    public function isRunning()
    {
        return (bool) $this->getIpc()->load('__running');
    }

    public function getResult()
    {
        return $this->getIpc()->load('__result');
    }

    public function getOutput()
    {
        return $this->getIpc()->load('__output');
    }

    public function getStatus()
    {
        return $this->getIpc()->load('__status');
    }

    public function isSuccessful()
    {
        return ($this->getIpc()->load('__status') == self::STATUS_SUCESS);
    }

    public function hasPid()
    {
        return (null !== $this->pid);
    }

    public function getPid()
    {
        if (false === $this->hasPid()) {
            $message = 'There is not defined process';
            throw new \UnderflowException($message);
        }

        return $this->pid;
    }

    public function getUserId()
    {
        return $this->userId;
    }

    public function getGroupId()
    {
        return $this->groupId;
    }

    public function getIpc()
    {
        return $this->ipc;
    }

}