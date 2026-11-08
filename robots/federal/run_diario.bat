@echo off
cd /d "%~dp0"
node federal.js >> robot.log 2>&1
