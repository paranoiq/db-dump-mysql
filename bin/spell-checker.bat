@ECHO OFF
setlocal DISABLEDELAYEDEXPANSION
SET BIN_TARGET=%~dp0/../vendor/spell-checker/spell-checker/spell-checker
php "%BIN_TARGET%" %*
