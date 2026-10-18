<?php
/**
 * BetterWorldDowngrader
 *
 * Downgrades the NetworkVersion tag in Bedrock level.dat files so that
 * worlds exported/saved by a newer Minecraft Bedrock version can be loaded
 * by an older PocketMine-MP server.
 *
 * PocketMine-MP refuses to load a world when level.dat contains a
 * NetworkVersion higher than the one it supports, e.g.:
 *
 *   Could not load world "Mappa": Unsupported format:
 *   LevelDB world protocol version 2223 is currently unsupported
 *
 * Usage:
 *   php downgrade.php <worlds-dir|world-dir> [options]
 *
 * Options:
 *   --target=<version>   NetworkVersion to write (default: 2168 = PMMP 5.50)
 *   --dry-run            Only report what would change, don't write anything
 *   --no-backup          Don't create a backup (NOT recommended)
 *   --restore            Restore level.dat from the backups created by this tool
 *
 * Examples:
 *   php downgrade.php "C:\servers\MyServer\worlds"
 *   php downgrade.php "C:\servers\MyServer\worlds\Mappa" --target=2168
 *   php downgrade.php "C:\servers\MyServer\worlds" --dry-run
 *   php downgrade.php "C:\servers\MyServer\worlds" --restore
 */

declare(strict_types=1);

const DEFAULT_TARGET = 2168; // PocketMine-MP 5.50
const BACKUP_SUFFIX = ".bak-before-downgrade";
const NEEDLE = "\x03\x0e\x00NetworkVersion"; // TAG_Int (0x03), LE name length (14), name

function usage(): void {
    echo <<<TXT
BetterWorldDowngrader — make newer Bedrock worlds loadable on PocketMine-MP

Usage:
  php downgrade.php <worlds-dir|world-dir> [options]

Options:
  --target=<version>   NetworkVersion to write (default: 2168)
  --dry-run            Report only, change nothing
  --no-backup          Skip the level.dat backup (NOT recommended)
  --restore            Restore level.dat from backups created by this tool

TXT;
}

/** @return array{dry-run:bool, no-backup:bool, restore:bool, target:int} */
function parseArgs(array $argv): array {
    $opts = [
        "dry-run" => false,
        "no-backup" => false,
        "restore" => false,
        "target" => DEFAULT_TARGET,
    ];
    $path = null;
    for($i = 1, $c = count($argv); $i < $c; $i++){
        $arg = $argv[$i];
        if($arg === "--dry-run"){
            $opts["dry-run"] = true;
        }elseif($arg === "--no-backup"){
            $opts["no-backup"] = true;
        }elseif($arg === "--restore"){
            $opts["restore"] = true;
        }elseif(str_starts_with($arg, "--target=")){
            $value = substr($arg, strlen("--target="));
            if(!ctype_digit($value)){
                fwrite(STDERR, "Invalid --target value: $value\n");
                exit(1);
            }
            $opts["target"] = (int) $value;
        }elseif(str_starts_with($arg, "--")){
            fwrite(STDERR, "Unknown option: $arg\n");
            usage();
            exit(1);
        }elseif($path === null){
            $path = $arg;
        }else{
            fwrite(STDERR, "Unexpected argument: $arg\n");
            exit(1);
        }
    }
    if($path === null){
        usage();
        exit(1);
    }
    return $opts + ["path" => $path];
}

/**
 * Find every world directory under the given path (a directory containing level.dat,
 * or a worlds directory containing such subdirectories).
 *
 * @return string[]
 */
function findWorlds(string $path): array {
    if(is_file($path)){
        return [];
    }
    $worlds = [];
    if(is_file($path . DIRECTORY_SEPARATOR . "level.dat")){
        $worlds[] = $path;
    }
    foreach((array) glob($path . DIRECTORY_SEPARATOR . "*", GLOB_ONLYDIR) ?: [] as $dir){
        if(is_file($dir . DIRECTORY_SEPARATOR . "level.dat")){
            $worlds[] = $dir;
        }
    }
    return $worlds;
}

function patchLevelDat(string $file, int $target, bool $dryRun, bool $backup): bool {
    $data = file_get_contents($file);
    if($data === false){
        fwrite(STDERR, "  [ERROR] Cannot read $file\n");
        return false;
    }

    $pos = strpos($data, NEEDLE);
    if($pos === false){
        fwrite(STDERR, "  [ERROR] NetworkVersion tag not found in $file (unexpected format?)\n");
        return false;
    }
    $valuePos = $pos + strlen(NEEDLE);
    $current = unpack("V", substr($data, $valuePos, 4))[1]; // little-endian int

    if($current <= $target){
        printf("  [OK] NetworkVersion = %d (already <= %d), nothing to do\n", $current, $target);
        return true;
    }

    printf("  [PATCH] NetworkVersion: %d -> %d\n", $current, $target);
    if($dryRun){
        echo "  [DRY-RUN] No changes written\n";
        return true;
    }

    if($backup){
        $backupFile = $file . BACKUP_SUFFIX;
        if(!copy($file, $backupFile)){
            fwrite(STDERR, "  [ERROR] Cannot create backup $backupFile\n");
            return false;
        }
        echo "  [BACKUP] " . basename($backupFile) . "\n";
    }

    $data[$valuePos]     = chr($target & 0xFF);
    $data[$valuePos + 1] = chr(($target >> 8) & 0xFF);
    $data[$valuePos + 2] = chr(($target >> 16) & 0xFF);
    $data[$valuePos + 3] = chr(($target >> 24) & 0xFF);

    if(file_put_contents($file, $data) === false){
        fwrite(STDERR, "  [ERROR] Cannot write $file\n");
        return false;
    }
    return true;
}

function restoreLevelDat(string $file): bool {
    $backupFile = $file . BACKUP_SUFFIX;
    if(!is_file($backupFile)){
        echo "  [SKIP] No backup found (" . basename($backupFile) . ")\n";
        return true;
    }
    if(!copy($backupFile, $file)){
        fwrite(STDERR, "  [ERROR] Cannot restore $backupFile\n");
        return false;
    }
    echo "  [RESTORED] level.dat restored from backup\n";
    return true;
}

$opts = parseArgs($argv);
$path = rtrim($opts["path"], "\\/");

if(!is_dir($path)){
    fwrite(STDERR, "Not a directory: $path\n");
    exit(1);
}

$worlds = findWorlds($path);
if($worlds === []){
    fwrite(STDERR, "No world (no level.dat) found under: $path\n");
    echo "Tip: pass the worlds folder of your server, or a single world folder.\n";
    exit(1);
}

printf("BetterWorldDowngrader — %d world(s) found in %s\n\n", count($worlds), $path);
if($opts["dry-run"]){
    echo "*** DRY-RUN: no files will be modified ***\n";
}
if($opts["restore"]){
    echo "*** RESTORE MODE: restoring from backups ***\n";
}else{
    printf("Target NetworkVersion: %d\n\n", $opts["target"]);
}

$ok = 0;
$failed = 0;
foreach($worlds as $world){
    printf("World: %s\n", basename($world));
    $success = $opts["restore"]
        ? restoreLevelDat($world . DIRECTORY_SEPARATOR . "level.dat")
        : patchLevelDat(
            $world . DIRECTORY_SEPARATOR . "level.dat",
            $opts["target"],
            $opts["dry-run"],
            !$opts["no-backup"]
        );
    $success ? $ok++ : $failed++;
    echo "\n";
}

printf("Done: %d OK, %d failed\n", $ok, $failed);
if(!$opts["dry-run"] && !$opts["restore"]){
    echo "NOTE: this only fixes the version check in level.dat. If a world still\n";
    echo "fails to load, its chunk data may use a format too new for your server.\n";
}
exit($failed === 0 ? 0 : 1);
