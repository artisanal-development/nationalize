# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Current state

This repository has no source code yet. Branch `main` has no commits, and the remote (`origin` → `https://github.com/artisanal-development/nationalize.git`) has no branches. There is no build system, test runner, linter, or README.

Once the project is scaffolded, replace this section with:
- Commands to build, lint, and run tests (including a single test)
- An overview of the architecture

## Environment notes

- This runs in a sandbox in direct mode: the host working tree is mounted here, so edits and commits show up on the host right away.
- The sandbox proxy handles GitHub authentication, so `git push` and `gh pr create` work from inside the sandbox even when `gh auth status` says you're not logged in.
