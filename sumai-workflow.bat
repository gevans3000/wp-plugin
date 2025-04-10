@echo off
REM Sumai 3-Commit Workflow Automation Script
REM Usage: sumai-workflow.bat start|continue|status

setlocal enabledelayedexpansion

REM Initialize variables
set COMMIT_COUNT_FILE=.commit_count
set MAX_COMMITS=3

REM Command handling
if "%~1"=="start" goto :start_cycle
if "%~1"=="continue" goto :continue_cycle
if "%~1"=="status" goto :check_status
if "%~1"=="commit" goto :do_commit

echo Invalid command. Usage: sumai-workflow.bat start|continue|status|commit "message"
exit /b 1

:start_cycle
echo ===== Starting new 3-commit cycle =====
echo 0 > %COMMIT_COUNT_FILE%
echo Commit count reset to 0
goto :end

:continue_cycle
if not exist %COMMIT_COUNT_FILE% (
    echo 0 > %COMMIT_COUNT_FILE%
    echo Created new commit count file
)
goto :check_status

:check_status
if not exist %COMMIT_COUNT_FILE% (
    echo No active commit cycle found
    exit /b 1
)

set /p CURRENT_COUNT=<%COMMIT_COUNT_FILE%
echo Current commit count: %CURRENT_COUNT% of %MAX_COMMITS%

if %CURRENT_COUNT% GEQ %MAX_COMMITS% (
    echo Commit cycle complete. Please review changes and start a new cycle.
    exit /b 0
)

echo Remaining commits: %MAX_COMMITS% - %CURRENT_COUNT% = !MAX_COMMITS! - !CURRENT_COUNT!
git status
goto :end

:do_commit
if "%~2"=="" (
    echo Missing commit message
    echo Usage: sumai-workflow.bat commit "TASK-ID: Commit message"
    exit /b 1
)

if not exist %COMMIT_COUNT_FILE% (
    echo 0 > %COMMIT_COUNT_FILE%
    echo Created new commit count file
)

set /p CURRENT_COUNT=<%COMMIT_COUNT_FILE%
set /a NEXT_COUNT=%CURRENT_COUNT%+1

echo ===== Commit %NEXT_COUNT% of %MAX_COMMITS% =====

REM Add all files
echo Adding all files...
git add --all
if %ERRORLEVEL% neq 0 (
    echo ERROR: Failed to add files
    exit /b 1
)

REM Only exclude TASKS.md if this is the final commit
if %NEXT_COUNT%==%MAX_COMMITS% (
    echo Final commit: Excluding TASKS.md...
    git reset -- TASKS.md
)

REM Commit with the provided message
echo Committing changes with message: %~2
git commit -m "%~2"
if %ERRORLEVEL% neq 0 (
    echo ERROR: Commit failed
    exit /b 1
)

REM Update commit count
echo %NEXT_COUNT% > %COMMIT_COUNT_FILE%
echo Commit count updated to %NEXT_COUNT%

REM Verify commit status
echo Verifying commit status...
git status

if %NEXT_COUNT%==%MAX_COMMITS% (
    echo.
    echo ===== 3-COMMIT CYCLE COMPLETE =====
    echo All files have been committed except TASKS.md which is prepared for the next cycle.
    echo Please review the changes and confirm to proceed with a new cycle.
) else (
    echo.
    echo Commit %NEXT_COUNT% of %MAX_COMMITS% completed successfully.
    echo Run 'sumai-workflow.bat continue' to proceed with the next commit.
)

:end
exit /b 0
