@echo off
rem Build the Micron OS ISO in WSL. Double-click, or run from cmd/PowerShell:
rem   tools\build-iso.cmd              (version from VERSION; a new RC number if that ISO exists)
rem   tools\build-iso.cmd --no-bump    (rebuild the current version; the old ISO is kept aside)
rem The WSL distribution is kali-linux unless MICRONOS_WSL_DISTRO says otherwise.
setlocal
if "%MICRONOS_WSL_DISTRO%"=="" set MICRONOS_WSL_DISTRO=kali-linux
set "REPO_WIN=%~dp0.."
for /f "usebackq delims=" %%i in (`wsl -d %MICRONOS_WSL_DISTRO% -u root -- wslpath -a "%REPO_WIN%"`) do set "REPO_WSL=%%i"
if "%REPO_WSL%"=="" (
  echo Could not reach WSL distribution %MICRONOS_WSL_DISTRO%.
  pause
  exit /b 1
)
wsl -d %MICRONOS_WSL_DISTRO% -u root -- bash "%REPO_WSL%/packaging/rebuild_iso.sh" %*
set RC=%ERRORLEVEL%
echo.
if %RC%==0 (echo Build finished. The ISO is in the folder next to this repository.) else (echo Build FAILED with code %RC%. Send the last 20 lines above.)
pause
exit /b %RC%
