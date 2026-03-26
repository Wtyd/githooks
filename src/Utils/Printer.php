<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Utils;

/**
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 */
class Printer
{
    public function resultSuccess(string $message): void
    {
        echo "✔️ ";
        $this->success($message);
    }

    public function resultWarning(string $message): void
    {
        echo "⚠️ ";
        $this->warning($message);
    }
    public function resultError(string $message): void
    {
        echo "❌ ";
        $this->error($message);
    }

    // Standard print
    public function line(string $message): void
    {
        echo "$message\n";
    }

    public function rawLine(string $message): void
    {
        echo $message;
    }

    public function emptyLine(): void
    {
        echo "\n";
    }

    /**
     * @param string $toolName
     * @param string $errorMessage
     */
    public function framedErrorBlock(string $toolName, string $errorMessage): void
    {
        $frameWidth = 80;
        $headLines = 20;
        $tailLines = 3;

        echo "  ┌" . str_repeat('─', $frameWidth - 1) . "\n";

        $lines = explode("\n", $errorMessage);
        $totalLines = count($lines);

        if ($totalLines <= $headLines + $tailLines) {
            foreach ($lines as $line) {
                echo "  │ $line\n";
            }
        } else {
            $omitted = $totalLines - $headLines - $tailLines;
            for ($i = 0; $i < $headLines; $i++) {
                echo "  │ " . $lines[$i] . "\n";
            }
            echo "  │ ... ($omitted lines omitted — run 'githooks tool $toolName' for full output)\n";
            for ($i = $totalLines - $tailLines; $i < $totalLines; $i++) {
                echo "  │ " . $lines[$i] . "\n";
            }
        }

        echo "  └" . str_repeat('─', $frameWidth - 1) . "\n";
    }

    /**
     * @param int $passed
     * @param int $total
     * @param array $toolResults Array of ['displayName' => string, 'success' => bool]
     */
    public function summary(int $passed, int $total, array $toolResults): void
    {
        $icon = ($passed === $total) ? '✔️' : '❌';
        echo "\nResults: $passed/$total passed $icon\n";
        foreach ($toolResults as $result) {
            $resultIcon = $result['success'] ? '✔️' : '❌';
            echo "  $resultIcon " . $result['displayName'] . "\n";
        }
    }

    // Green
    public function info(string $message): void
    {
        $this->success($message);
    }

    public function success(string $message): void
    {
        echo "\e[42m\e[30m$message\033[0m\n";
    }

    // Red
    public function error(string $message): void
    {
        echo "\e[41m\e[30m$message\033[0m\n";
    }

    // Yellow
    public function comment(string $message): void
    {
        $this->warning($message);
    }

    public function warning(string $message): void
    {
        echo "\e[43m\e[30m$message\033[0m\n";
    }
}
