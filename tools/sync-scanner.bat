@echo off
REM ===================================================================
REM  sync-scanner.bat  --  keep the two scanner copies identical
REM
REM  The scanner exists in two places on purpose:
REM
REM    \spck_scanner.html          the copy the WEBSITE serves
REM                                (Guards open it from the sidebar)
REM    \phone_scanner\             the copy you PASTE ONTO A PHONE
REM                                (open this folder in SPCK Editor)
REM
REM  The website copy is the master. Edit that one. Then run this file
REM  and phone_scanner\ is refreshed from it.
REM
REM  Before this existed the two copies drifted apart for weeks -- each
REM  had fixes the other was missing and there was no way to tell which
REM  was newer. Do not hand-edit files inside phone_scanner\.
REM
REM  Usage: double-click this file, or run it from a terminal.
REM ===================================================================
setlocal
set "ROOT=%~dp0.."
set "DEST=%ROOT%\phone_scanner"

echo.
echo  Syncing the phone scanner package...
echo  from: %ROOT%
echo    to: %DEST%
echo.

if not exist "%DEST%" mkdir "%DEST%"
if not exist "%DEST%\assets\icons" mkdir "%DEST%\assets\icons"

REM -- the app and its offline plumbing --
for %%F in (spck_scanner.html scanner-sw.js scanner.webmanifest) do (
    copy /Y "%ROOT%\%%F" "%DEST%\%%F" >nul && echo    [ok] %%F
)

REM -- bundled libraries, so the phone needs no internet --
for %%F in (html5-qrcode.min.js xlsx.full.min.js bcrypt.min.js) do (
    copy /Y "%ROOT%\%%F" "%DEST%\%%F" >nul && echo    [ok] %%F
)

REM -- app icons --
copy /Y "%ROOT%\assets\icons\*.png" "%DEST%\assets\icons\" >nul && echo    [ok] assets\icons\*.png

echo.
echo  Done. Copy the whole "phone_scanner" folder to the phone,
echo  open THE FOLDER in SPCK Editor, then Run spck_scanner.html.
echo.
endlocal
pause
