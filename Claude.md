# Claude.md

## Project Objective

Yii2 Swoole extension, provides coroutine http server and more coroutine features.

## Documents

- swoole https://wiki.swoole.com/zh-cn/#/
- yii2 api https://www.yiiframework.com/doc/api/2.0
- yii2 source repo https://github.com/yiisoft/yii2
- yii2 redis https://github.com/yiisoft/yii2-redis
- yii2 queue https://github.com/yiisoft/yii2-queue

## Directory Structure

- examples/: yii2 applications for demonstrating how to use yii2-swoole
- src/: yii2-swoole extension for integrating swoole coroutine into yii2

## Coding Standards

- Always write clean code.
- Only add necessary comments and avoid redundant comments.

## Git Actions

- Never commit to git after each code change, unless with user's explicit approval to do that.
- Approval is ONE TIME, meaning each commit requires separate explicit approval.
- Split changes by group to commit, maintaining code quality by organizing related changes into separate commits.
- Commit message should be concise and descriptive, avoid verbose commit messages.