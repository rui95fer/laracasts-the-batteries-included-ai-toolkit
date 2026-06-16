# Laravel Guidelines

Use these rules when writing Laravel code.

## Simplicity

Simple beats clever.

Write code that makes the reader feel smart, not the author.

Prefer clarity over compactness.

More lines are acceptable when they make the code easier to understand.

Avoid clever one-liners when simple control flow is clearer.

## Laravel Conventions

Follow Laravel conventions by default.

Put things where Laravel expects them.

Name things the way Laravel expects them.

Do not add custom structure, naming, or configuration unless there is a clear reason.

When Laravel convention works, use it.

## Readable Code

Write code that clearly expresses intent.

Use readable names for variables, methods, scopes, relationships, and classes.

Prefer code that can be read like a sentence.

Use Eloquent, collections, and validation in the expressive style Laravel encourages.

## Use Laravel First

Before building a custom solution, check whether Laravel already solves the problem.

Use Laravel’s built-in features before creating custom code.

Do not rebuild what the framework already provides.

The code you do not write is the code that never has bugs.

## Do Not Fight Laravel

Do not use PHP features or custom patterns in ways that bypass Laravel’s lifecycle, conventions, or tooling.

When working with Eloquent models, use Eloquent’s model features.

Use PHP features where they fit Laravel.

Do not use PHP features just because they are new or clever.

## Avoid Premature Abstraction

Solve the problem in front of you.

Do not build for imaginary requirements.

Do not create interfaces, factories, managers, repositories, or abstraction layers just because they might be useful later.

Add abstraction only when there is a real reason.

The best abstraction is written after there are multiple real use cases, not before.

## Repositories

Do not use the Repository Pattern by default in Laravel applications.

Eloquent is already the data access layer.

Do not create repositories that only wrap Eloquent methods.

Use Eloquent directly.

Use query scopes when query logic needs a clear name.

Only introduce a repository when there is a real reason.

## Favor the Reader

Code is read more than it is written.

Choose clarity over brevity.

Choose boring over clever.

Choose explicit over implicit.

Prefer code that a new team member can understand quickly without needing an explanation.

If code needs a comment to explain what it does, make the code clearer first.

Use comments to explain why, not what.

## Three Questions

Before writing code, ask:

1. Is this the simplest way?
2. Will a new team member understand it quickly?
3. Am I following Laravel conventions?

If the answer is no, simplify before continuing.
