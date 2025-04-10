@echo off
REM Sumai 3-Commit Workflow Automation Script
REM Usage: sumai-commit.bat "TASK-ID: Commit message"

echo ===== Sumai Commit Process =====

REM Count parameter validation
if "%~1"=="" (
    echo ERROR: Missing commit message
    echo Usage: sumai-commit.bat "TASK-ID: Commit message"
    exit /b 1
)

REM Add all files
echo Adding all files...
git add --all
if %ERRORLEVEL% neq 0 (
    echo ERROR: Failed to add files
    exit /b 1
)

REM Reset TASKS.md to ensure it's not committed at the end of a cycle
echo Excluding TASKS.md from commit...
git reset -- TASKS.md
if %ERRORLEVEL% neq 0 (
    echo WARNING: Failed to exclude TASKS.md
)

REM Commit with the provided message
echo Committing changes with message: %~1
git commit -m %1
if %ERRORLEVEL% neq 0 (
    echo ERROR: Commit failed
    exit /b 1
)

REM Verify commit status
echo Verifying commit status...
git status
echo.
echo Commit process completed successfully.
echo ================================
