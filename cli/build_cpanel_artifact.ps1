param(
    [switch]$IncludeLocalConfig
)

$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName System.IO.Compression
Add-Type -AssemblyName System.IO.Compression.FileSystem

$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$artifactDir = Join-Path $projectRoot 'deployment-artifacts'
$artifactPath = Join-Path $artifactDir 'attendance-cpanel-upload.zip'
$temporaryPath = Join-Path $artifactDir 'attendance-cpanel-upload.new.zip'
New-Item -ItemType Directory -Path $artifactDir -Force | Out-Null

$files = New-Object 'System.Collections.Generic.List[string]'
foreach ($name in @('.htaccess', 'index.php', 'login.php', 'logout.php',
        'forgot_password.php', 'reset_password.php', 'setup_account.php',
        'manifest.webmanifest', 'sw.js', 'notifications.php', 'notification_open.php')) {
    $path = Join-Path $projectRoot $name
    if (Test-Path -LiteralPath $path -PathType Leaf) { $files.Add($path) }
}

foreach ($directory in @('admin', 'api', 'assets/css', 'assets/img', 'assets/js',
        'assets/lang', 'assets/textures', 'includes', 'staff')) {
    $path = Join-Path $projectRoot $directory
    if (Test-Path -LiteralPath $path -PathType Container) {
        Get-ChildItem -LiteralPath $path -File -Recurse | ForEach-Object { $files.Add($_.FullName) }
    }
}

foreach ($name in @('config/.htaccess', 'config/config.php', 'config/database.php',
        'config/local.php.example', 'cli/.htaccess', 'cli/create_admin.php',
        'cli/daily_attendance.php', 'cli/maintenance.php', 'cli/notification_reminders.php', 'cli/preflight.php',
        'storage/.htaccess', 'uploads/profiles/.htaccess', 'uploads/profiles/.gitkeep')) {
    $path = Join-Path $projectRoot $name
    if (-not (Test-Path -LiteralPath $path -PathType Leaf)) {
        throw "Required deployment file is missing: $name"
    }
    $files.Add($path)
}

if ($IncludeLocalConfig) {
    $localPath = Join-Path $projectRoot 'config/local.php'
    if (-not (Test-Path -LiteralPath $localPath -PathType Leaf)) {
        throw 'config/local.php is missing'
    }
    $files.Add($localPath)
}

if (Test-Path -LiteralPath $temporaryPath) {
    Remove-Item -LiteralPath $temporaryPath -Force
}

$zip = [IO.Compression.ZipFile]::Open($temporaryPath, [IO.Compression.ZipArchiveMode]::Create)
try {
    foreach ($path in ($files | Sort-Object -Unique)) {
        $entryName = $path.Substring($projectRoot.Length + 1).Replace('\', '/')
        [IO.Compression.ZipFileExtensions]::CreateEntryFromFile(
            $zip, $path, $entryName, [IO.Compression.CompressionLevel]::Optimal
        ) | Out-Null
    }
    foreach ($directory in @('storage/', 'uploads/profiles/')) {
        [void]$zip.CreateEntry($directory)
    }
} finally {
    $zip.Dispose()
}

Copy-Item -LiteralPath (Join-Path $projectRoot 'database/cpanel_schema.sql') -Destination (Join-Path $artifactDir 'cpanel_schema.sql') -Force
Copy-Item -LiteralPath (Join-Path $projectRoot 'database/cpanel_seed.sql') -Destination (Join-Path $artifactDir 'cpanel_seed.sql') -Force
Copy-Item -LiteralPath (Join-Path $projectRoot 'database/migration_attendance_location.sql') -Destination (Join-Path $artifactDir 'migration_attendance_location.sql') -Force
Copy-Item -LiteralPath (Join-Path $projectRoot 'database/migration_attendance_location_preference.sql') -Destination (Join-Path $artifactDir 'migration_attendance_location_preference.sql') -Force
Copy-Item -LiteralPath (Join-Path $projectRoot 'database/migration_push_notifications.sql') -Destination (Join-Path $artifactDir 'migration_push_notifications.sql') -Force
Copy-Item -LiteralPath (Join-Path $projectRoot 'database/migration_notification_center.sql') -Destination (Join-Path $artifactDir 'migration_notification_center.sql') -Force

Move-Item -LiteralPath $temporaryPath -Destination $artifactPath -Force
Write-Output "Built $artifactPath with $($files.Count) files; local config included: $IncludeLocalConfig"
