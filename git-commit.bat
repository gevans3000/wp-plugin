@echo off
REM Automated git commit script for Sumai plugin
REM Usage: git-commit.bat "Commit message"

echo Running automated commit process...

REM Add all files
git add --all

REM Reset TASKS.md to ensure it's not committed
git reset -- TASKS.md

REM Commit with the provided message
git commit -m %1

REM Show status to verify
git status

echo Commit process completed.
