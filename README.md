# BetterWorldDowngrader

A tiny dependency-free PHP CLI tool that makes **newer Minecraft Bedrock worlds loadable on PocketMine-MP servers**.

## The problem

When you export or save a world with a recent version of Minecraft Bedrock, the world's `level.dat` records the game's `NetworkVersion`. PocketMine-MP refuses to load worlds newer than it supports:

```
Could not load world "Mappa": Unsupported format:
LevelDB world protocol version 2223 is currently unsupported
```

## The fix

This tool patches the `NetworkVersion` integer inside `level.dat` down to the value your server supports, so PocketMine-MP accepts the world again.

- Always creates a **backup** of `level.dat` before touching it (`level.dat.bak-before-downgrade`)
- Can process a **whole worlds folder** at once or a **single world**
- **Dry-run** mode to preview changes without writing anything
- **Restore** mode to undo everything from the backups
- No dependencies, single file, works on PHP 8.0+

## Requirements

- PHP 8.0+ (CLI) — the same PHP you use to run PocketMine-MP is fine

## Usage

```bash
# Convert every world in a server's worlds folder
php downgrade.php "C:\servers\MyServer\worlds"

# Convert a single world
php downgrade.php "C:\servers\MyServer\worlds\Mappa"

# Preview without changing anything
php downgrade.php "C:\servers\MyServer\worlds" --dry-run

# Choose the target NetworkVersion (default: 2168 = PocketMine-MP 5.50)
php downgrade.php "C:\servers\MyServer\worlds" --target=2168

# Undo everything (restore level.dat from the backups)
php downgrade.php "C:\servers\MyServer\worlds" --restore
```

### Options

| Option          | Description                                              |
|-----------------|----------------------------------------------------------|
| `--target=<v>`  | NetworkVersion to write (default: `2168`)                |
| `--dry-run`     | Report what would change, write nothing                  |
| `--no-backup`   | Skip the `level.dat` backup (NOT recommended)            |
| `--restore`     | Restore `level.dat` from the backups created by this tool |

## Which target version do I need?

Use the `NetworkVersion` your PocketMine-MP build supports. The error message in your server log tells you both numbers:

```
LevelDB world protocol version 2223 is currently unsupported
                     ^^^^ world's version
```

For **PocketMine-MP 5.50**,(BetterLite Fork) the supported version is **2168**. If you run a different build, check the value in `src/data/bedrock/WorldDataVersions.php` (`NETWORK` constant) inside the PocketMine-MP phar, or simply run this tool with the default and read what it reports.

## ⚠️ Limitations

This tool fixes the **version check** in `level.dat`. It does **not** convert the actual chunk data stored in the LevelDB database (`db/*.ldb`).

In most cases worlds load fine after the downgrade, but if a world was saved by a *much* newer game version, chunks may contain block states or formats your server doesn't know yet, which can result in:

- unknown blocks appearing as air/stone
- errors while loading specific chunks

If that happens, the safest path is to re-export the world with a game version closer to the one your server supports, or convert it with a tool like [Amulet Editor](https://www.amulet-editor.com/).

Always keep a **full backup of your worlds folder** before downgrading.

## License

MIT
