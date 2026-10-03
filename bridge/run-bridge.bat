@echo off
REM ---------------------------------------------------------------------------
REM Double-click this to start the bridge agent.
REM
REM It polls the fingerprint terminal and forwards punches to the HRMS. Leave
REM the window open: closing it stops the agent, and nothing is collected while
REM it is not running. Nothing is lost when it stops -- the terminal keeps its
REM log and the local queue keeps anything undelivered -- but attendance will
REM not appear in the system until it is running again.
REM
REM Close ZKTime first. Only one program may talk to the terminal at a time.
REM ---------------------------------------------------------------------------

cd /d "%~dp0"

echo ============================================================
echo  Biometric bridge agent
echo ============================================================
echo.
echo  Close ZKTime before continuing - only one program can talk
echo  to the terminal at a time.
echo.
echo  Leave this window open. Press Ctrl+C to stop.
echo.
echo ============================================================
echo.

where python >nul 2>nul
if errorlevel 1 (
    echo ERROR: Python was not found.
    echo.
    echo Install Python 3 from python.org and tick "Add Python to PATH"
    echo during setup, then run this file again.
    echo.
    pause
    exit /b 1
)

if not exist "bridge.env" (
    echo ERROR: bridge.env is missing.
    echo.
    echo Copy bridge.env.example to bridge.env and fill in the device
    echo address and the shared secret, then run this file again.
    echo.
    pause
    exit /b 1
)

python bridge_agent.py %*

echo.
echo ============================================================
echo  The agent has stopped. Nothing was lost -- anything not yet
echo  delivered is queued and will go on the next run.
echo ============================================================
echo.
pause
