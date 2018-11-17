<?php declare(strict_types=1);

/*
 * Standalone CLI TeamSpeak3 Server Updater Script.
 * The MIT License (MIT)
 *
 * Copyright (c) 2018 Benjamin Rannow <rannow@emerise.de>
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND,
 * EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF
 * MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT.
 * IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM,
 * DAMAGES OR OTHER LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR
 * OTHERWISE, ARISING FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE
 * OR OTHER DEALINGS IN THE SOFTWARE.
 */

class TSUpdater
{
    private const LOCAL_TS3_LOCATION = '/usr/local/bin/teamspeak3-server_linux_amd64';
    private const REMOTE_MANIFEST_URL = 'https://www.teamspeak.com/versions/server.json';
    
    private const TEMP_DIRECTORY = '/tmp/teamspeakDownload';
    private const VERSION_FILE = 'CURRENT_VERSION';
    
    private const TEAMSPEAK_SYSTEM_USER = 'teamspeak';
    private const TEAMSPEAK_SYSTEM_GROUP = 'nogroup';
    
    private const SCRIPT_USER_AGENT = 'Teamspeak Update Script v1.0';
    
    /**
     * @var array
     */
    private static $runtimeCache = [];
    
    /**
     *
     */
    public function main(): void
    {
        self::$runtimeCache = [];
        if ($this->isTSServerOutdated()) {
            echo 'version is outdated' . PHP_EOL;
            $this->updateTsServer();
        } else {
            echo 'version is up to date' . PHP_EOL;
        }
    }
    
    /**
     * skip version check
     */
    public function forceUpdate(): void
    {
        $this->updateTsServer();
    }
    
    /**
     *
     */
    private function updateTsServer(): void
    {
        if($this->downloadTeamspeakVersion()) {
            $this->installDownloadVersion();
            $this->cleanup();
        }
    }
    
    /**
     *
     */
    private function cleanup(): void
    {
        $this->removeTempDirectory();
    }
    
    /**
     *
     */
    private function installDownloadVersion(): void
    {
        $downloadFilePath = self::$runtimeCache['downloadFilePath'];
        if (file_exists($downloadFilePath)) {
            $extractionDir = $this->extractDownloadFile($downloadFilePath);
            if ($extractionDir) {
                $this->injectVersionFile($extractionDir);
                $this->setTeamspeakOwnerForDirectory($extractionDir);
                
                $this->sendTSCommand('stop');
                $this->copyExtractedData($extractionDir);
                $this->sendTSCommand('start');
            }
        }
    }
    
    /**
     * @param string $sourceLocation
     */
    private function copyExtractedData(string $sourceLocation): void
    {
        if (!file_exists(self::LOCAL_TS3_LOCATION)) {
            mkdir(self::LOCAL_TS3_LOCATION, 0755, true);
            $this->setTeamspeakOwnerForDirectory(self::LOCAL_TS3_LOCATION);
        }
        
        $sourceFiles = rtrim($sourceLocation, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '*';
        exec('rsync -a -I '. $sourceFiles . ' '. self::LOCAL_TS3_LOCATION);
    }
    
    /**
     * @param string $archiveFile
     * @return string
     */
    private function extractDownloadFile(string $archiveFile): string
    {
        $targetDirectory = rtrim(self::TEMP_DIRECTORY, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR. md5((string)microtime());
        try {
            $archive = new PharData($archiveFile);
            if ($archive) {
                // extract it to the path we determined above
                $archive->extractTo($targetDirectory, null, true);
                return $this->getRealServerDirectory($targetDirectory);
            }
        } catch (\Exception $_) {
            return '';
        }
        
        return '';
    }
    
    /**
     * @param string $directory
     */
    private function setTeamspeakOwnerForDirectory(string $directory): void
    {
        exec('chown -R '.  self::TEAMSPEAK_SYSTEM_USER . ':'. self::TEAMSPEAK_SYSTEM_GROUP .' '. $directory);
    }
    
    /**
     * @param string $extractionDirectory
     * @return string
     */
    private function getRealServerDirectory(string $extractionDirectory): string
    {
        $iterator = new RecursiveDirectoryIterator($extractionDirectory);
        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator($iterator) as $file) {
            if ($file->isFile()) {
                if ($file->getFilename() === 'ts3server' || $file->getFilename() === 'ts3server.exe' || $file->getFilename() === 'LICENSE') {
                    return realpath($file->getPath());
                }
            }
        }
        
        return '';
    }
    
    /**
     * @param string $directory
     */
    private function injectVersionFile(string $directory): void
    {
        if (file_exists($directory) && is_dir($directory)) {
            $versionString = implode('.',$this->getRemoteVersion());
            $versionFile = rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . self::VERSION_FILE;
            file_put_contents($versionFile, $versionString . PHP_EOL);
        }
    }
    
    /**
     * @return bool
     */
    private function downloadTeamspeakVersion(): bool
    {
        if ($this->prepareDownloadLocation()) {
            $remotePath = $this->getRemoteDownloadPath();
            if (!empty($remotePath)) {
                return $this->loadFileFromServer($remotePath, self::TEMP_DIRECTORY);
            }
        }
        
        return false;
    }
    
    /**
     * @return bool
     */
    private function prepareDownloadLocation(): bool
    {
        $this->removeTempDirectory();
        try {
            if (!mkdir(self::TEMP_DIRECTORY,0700, true)) {
                return false;
            }
        } catch (Exception $_) {
            return false;
        }
    
        // linux based - empty dirs are 2 (. and ..)
        $fileCount = iterator_count(new DirectoryIterator(self::TEMP_DIRECTORY));
        return file_exists(self::TEMP_DIRECTORY) && $fileCount === 2;
    }
    
    /**
     *
     */
    private function removeTempDirectory(): void
    {
        if (file_exists(self::TEMP_DIRECTORY)) {
            exec('rm -r '. self::TEMP_DIRECTORY);
        }
    }
    
    /**
     * @param string $remoteAddr
     * @param string $localPath
     * @return bool
     */
    private function loadFileFromServer(string $remoteAddr, string $localPath): bool
    {
        $fileName = crc32((string)microtime()) . basename($remoteAddr);
        $localPath = rtrim($localPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $fileName;
        $targetHandle = fopen($localPath, 'w');
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_FILE, fopen($localPath, 'w'));
        curl_setopt($ch, CURLOPT_URL, $remoteAddr);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_FAILONERROR, true);
        curl_setopt($ch, CURLOPT_USERAGENT, 'User-Agent: ' . self::SCRIPT_USER_AGENT);
    
        
        if (!curl_exec($ch)) {
            if (is_resource($targetHandle)) {
                fclose($targetHandle);
            }
            return false;
        }
    
        if (is_resource($targetHandle)) {
            fclose($targetHandle);
        }
        return $this->validateRemoteDownload();
    }
    
    /**
     * @return bool
     */
    private function validateRemoteDownload(): bool
    {
        $checkSum = $this->getRemoteChecksum();
        foreach (new DirectoryIterator(self::TEMP_DIRECTORY) as $file) {
            if ($file->isFile()) {
                $filePath = $file->getRealPath();
                if ($checkSum === hash_file('sha256', $filePath)) {
                    self::$runtimeCache['downloadFilePath'] = $filePath;
                    return true;
                }
            }
        }
        
        return false;
    }
    
    /**
     * @return bool
     */
    private function isTSServerOutdated(): bool
    {
        $localVersion = $this->getLocalVersion();
        $remoteVersion = $this->getRemoteVersion();
        return $this->compareVersions($localVersion, $remoteVersion) === -1;
    }
    
    /**
     * @param array $localVersion
     * @param array $remoteVersion
     * @return int -1, 0, 1
     *
     * -1 (remote version is higher)
     * 0 (both versions are equal)
     * 1 (local version is higher)
     */
    private function compareVersions(array $localVersion, array $remoteVersion): int
    {
        $versionDigitCount = count($localVersion);
        $remoteDigitCount = count($remoteVersion);
        if ($versionDigitCount !== $remoteDigitCount) {
            return -1;
        }
        
        for ($i = 0; $i < $versionDigitCount; ++$i) {
            if ($localVersion[$i] > $remoteVersion[$i]) {
                return 1;
            } elseif ($localVersion[$i] < $remoteVersion[$i] ) {
                return -1;
            }
        }
        
        return 0;
    }
    
    /**
     * @return array tuple (string, string)
     */
    private function getSystemArchitecture(): array
    {
        $kernelName = php_uname('s');
        $kernelMap = [
            'Darwin' => 'macos',
            'Windows' => 'windows',
            'FreeBSD' => 'freebsd',
            'Linux' => 'linux'
        ];
    
        $architecture = php_uname('m');
        $architectureMap = [
            'x86' => 'x86',
            'x86_64' => 'x86_64',
            'x64-based PC' => 'x86_64',
            'x86-based PC' => 'x86',
        ];
    
        return [
            $kernelMap[$kernelName]??'',
            $architectureMap[$architecture]??''
        ];
    }
    
    /**
     * @return array
     */
    private function loadRemoteManifest(): array
    {
        $manifest = [];
        $context = stream_context_create(
            [
                'http' => [
                    'header' => 'User-Agent: ' . self::SCRIPT_USER_AGENT
                ]
            ]
        );
        $data = file_get_contents(self::REMOTE_MANIFEST_URL, false, $context);
        if (!empty($data)) {
            $manifest = json_decode($data, true, 5);
    
            if ($manifest === null) {
                return [];
            }
        }
        
        return $manifest;
    }
    
    /**
     * @return array
     */
    private function getRemoteReleaseData(): array
    {
        if (isset(self::$runtimeCache[__FUNCTION__])) {
            return self::$runtimeCache[__FUNCTION__];
        }
        
        list($systemName, $arch) = $this->getSystemArchitecture();
        $manifest = $this->loadRemoteManifest();
        
        $remoteData = [];
        if ($systemName && $arch && isset($manifest[$systemName][$arch])) {
            $remoteData = $manifest[$systemName][$arch];
        }
        
        self::$runtimeCache[__FUNCTION__] = $remoteData;
        return $remoteData;
    }
    
    /**
     * @return array tuple (int, int, int)
     */
    private function getRemoteVersion(): array
    {
        $releaseData = $this->getRemoteReleaseData();
        $remoteVersion = $releaseData['version']??'0.0.0';
        list ($major, $minor, $patch) = explode('.', $remoteVersion);
        return [
            (int)$major,
            (int)$minor,
            (int)$patch
        ];
    }
    
    /**
     * @return string
     */
    private function getRemoteChecksum(): string
    {
        $releaseData = $this->getRemoteReleaseData();
        return $releaseData['checksum']??'';
    }
    
    /**
     * @return string
     */
    private function getRemoteDownloadPath(): string
    {
        $releaseData = $this->getRemoteReleaseData();
        $mirrors = $releaseData['mirrors']??[];
        foreach ($mirrors as $name => $location) {
            return $location;
        }
        
        return '';
    }
    
    /**
     * @return array tuple (int, int, int)
     */
    private function getLocalVersion(): array
    {
        $versionFile = self::LOCAL_TS3_LOCATION. DIRECTORY_SEPARATOR . self::VERSION_FILE;
        $versionString = '0.0.0';
        if (file_exists($versionFile) && is_file($versionFile)) {
            $versionString = trim(file_get_contents($versionFile));
        }
        
        list ($major, $minor, $patch) = explode('.', $versionString);
        return [
            (int)$major,
            (int)$minor,
            (int)$patch
        ];
    }
    
    /**
     * @param string $command
     * @param int $waiting
     */
    public function sendTSCommand(string $command, int $waiting = 3): void
    {
        if ($command === 'start' || $command === 'stop') {
            
            $tsStarterScript = rtrim(self::LOCAL_TS3_LOCATION, DIRECTORY_SEPARATOR) .
                DIRECTORY_SEPARATOR .
                'ts3server_startscript.sh';
            
            if (!file_exists($tsStarterScript)) {
                return;
            }
            
            $output = '';
            exec('sudo -u '. self::TEAMSPEAK_SYSTEM_USER .' bash '.
                $tsStarterScript .' '. $command, $output);
            sleep($waiting);
        }
    }
}

$tsUpdater = new TSUpdater();
$tsUpdater->main();
