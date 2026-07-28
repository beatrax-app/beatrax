<!--
  Template for `.docs/features/<module-slug>/code.md`. Fill in per-module.
  This file is the map from "I need to change behaviour X" to "the
  files X lives in". Keep paths up to date when the code moves.
-->

# `<ModuleName>` — code

The file-level map for the module. A reader who wants to extend or
debug `<ModuleName>` starts here.

## Directory layout

```
Modules/<ModuleName>/
├── Public/
│   ├── Contracts/
│   ├── Dto/
│   ├── Events/
│   └── Services/
├── Internal/
│   ├── Actions/
│   ├── Console/
│   ├── Http/
│   ├── Jobs/
│   ├── Listeners/
│   └── ...
├── Models/
├── Database/
│   ├── Migrations/
│   ├── Seeders/
│   └── Factories/
├── Routes/
├── Resources/
├── Providers/
└── tests/
```

Use the actual directories present in the module — drop the lines that
don't apply.

## Public API

Bulleted list of every class under `Public/`, grouped by subdirectory:

- **Contracts/**
  - `ContractInterfaceName` — what callers use it for.
- **DTOs/**
  - `DtoClassName` — what value it carries.
- **Events/**
  - `EventClassName` — when raised, what listeners typically do with it.
- **Services/**
  - `ServiceClassName` — what behaviour it exposes.

Listing what is publicly exported is half the value of this file —
adding a new public class without updating this list is a code smell.

## Internal services

Bulleted list of the load-bearing internal classes. Skip the trivial
ones; cover the ones a reader needs to know about to understand the
module:

- `Internal/Pipeline/StageClassName` — what stage of the pipeline it
  handles.
- `Internal/Jobs/JobClassName` — what background work it does.
- `Internal/Listeners/ListenerClassName` — what event it reacts to.

## Models + migrations

For each Eloquent model owned by the module:

- `Models/ModelName` — what table it maps to, what trait composition
  it has (typically `BelongsToUser`, see
  [ADR 0008](https://github.com/beatrax-app/spec/blob/main/00-overview/decisions/0008-multi-user-belongstouser.md)), what casts
  are non-trivial (Money columns, JSON columns, state enums).

For the migration history:

- `Database/Migrations/<timestamp>_create_<table>_table.php` — the
  initial create.
- Subsequent migrations grouped by the column / index they introduce.

The migrations are append-only; this list grows over time but never
shrinks.

## Provider wiring

What `Modules/<ModuleName>/Providers/<ModuleName>ServiceProvider.php`
binds in the container — every Public/Contracts → Internal/Service
binding, the schedule registrations, the Livewire component
registrations, the event-to-listener bindings.

This section is the answer to "how does the public contract resolve to
the internal implementation?".
