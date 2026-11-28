@echo off
REM GradeView Desktop Sync Helper launcher
REM Double-click this file to start the helper. Keep it running (system tray)
REM while you use GradeView's "Open" button.

cd /d "%~dp0"

where py >nul 2>nul
if %errorlevel%==0 (
    py gradeview_helper.py
    goto end
)

where python >nul 2>nul
if %errorlevel%==0 (
    python gradeview_helper.py
    goto end
)

echo Python was not found on this PC.
echo Install Python 3 from https://www.python.org/downloads/ and run this again.
pause

:end
pause