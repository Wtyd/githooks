<?php

namespace Tests\Doubles;

use Wtyd\GitHooks\Utils\FileUtils;

class FileUtilsFake extends FileUtils
{
    protected array $modifiedFiles = [];

    protected string $currentBranch = 'main';

    protected array $filesThatShouldBeFoundInDirectories = [];

    public function getModifiedFiles(): array
    {
        return $this->modifiedFiles;
    }

    public function setModifiedfiles(array $modifiedFiles): void
    {
        $this->modifiedFiles = $modifiedFiles;
    }

    public function setFilesThatShouldBeFoundInDirectories(array $filesThatShouldBeFoundInDirectories): void
    {
        $this->filesThatShouldBeFoundInDirectories = $filesThatShouldBeFoundInDirectories;
    }

    public function directoryContainsFile(string $directory, string $file): bool
    {
        if (in_array($file, $this->filesThatShouldBeFoundInDirectories)) {
            return true;
        }
        return false;
    }

    public function getCurrentBranch(): string
    {
        return $this->currentBranch;
    }

    public function setCurrentBranch(string $branch): void
    {
        $this->currentBranch = $branch;
    }

    /** @var string[]|null */
    protected ?array $branchDiffFiles = null;

    protected ?string $detectedMainBranch = null;

    public function setBranchDiffFiles(?array $files): void
    {
        $this->branchDiffFiles = $files;
    }

    public function getBranchDiffFiles(string $mainBranch): ?array
    {
        return $this->branchDiffFiles;
    }

    public function setDetectedMainBranch(?string $branch): void
    {
        $this->detectedMainBranch = $branch;
    }

    public function detectMainBranch(): ?string
    {
        return $this->detectedMainBranch;
    }

    /** @var array<string, string[]> */
    protected array $directoryExpansions = [];

    /**
     * @param string[] $files
     */
    public function setDirectoryExpansion(string $directory, array $files): void
    {
        $this->directoryExpansions[$directory] = $files;
    }

    /**
     * @inheritDoc
     */
    public function expandDirectory(string $directory, array $extensions): array
    {
        $files = $this->directoryExpansions[$directory] ?? [];

        if (empty($extensions)) {
            return $files;
        }

        $extSet = array_flip(array_map('strtolower', $extensions));

        return array_values(array_filter($files, static function (string $file) use ($extSet): bool {
            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            return $ext !== '' && isset($extSet[$ext]);
        }));
    }
}
