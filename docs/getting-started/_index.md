---
title: Getting started
weight: 10
description: Install the engine, understand what it binds, and test against it.
---

# Getting started

The package auto-registers `TaxServiceProvider`, which binds the calculator, the
shipped regime registry and the register-backed sources. Nothing to migrate — but the
register has to be synced before the engine will price anything.

- [Installation](installation.md) — composer require, then `tax:data:sync`.
- [The register](the-register.md) — where the data comes from, the commands, the licence.
- [Testing](testing.md) — build a register in three lines with `FakeRegister`.
- [Upgrading from 0.9](upgrading.md) — the data plane, the contract signatures and
  the numbers that move on their own.
