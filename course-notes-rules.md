# Course Notes Rules

Use this file as the standard for taking notes from any programming course.

The goal is not to write a transcript. The goal is to create useful notes that help you remember the main ideas from each episode and apply them later.

---

## The Structure

For each episode, alternate between lessons and their matching examples:

- Lesson (one bullet)
- Example directly under that lesson
- Lesson (one bullet)
- Example directly under that lesson

**Do not** separate all lessons first and all examples later.

**Template:**

````md
## Episode 01 — Episode Title

- **Short, single lesson.**
  ```php
  // Small snippet that demonstrates this lesson
  ```

- **Another short, single lesson.**
  ```php
  // Small snippet that demonstrates this lesson
  ```
````

---

## Rules for Lessons

### 1. Keep lessons concise and meaningful

Use one bullet per idea.

- Default: one sentence.
- Optional: add one short explanation sentence (`why` or `when`).
- If it needs more than two short lines, split into multiple bullets.

Good:

```md
- Controllers should stay small because mixing validation, authorization, database logic, and response formatting makes methods harder to read and maintain.
```

Avoid:

```md
- A single bullet that combines multiple rules or multiple decisions.
```

---

### 2. Use simple words

Write notes in your own words.

Good:

```md
- A migration is a version-controlled change to the database.
```

Avoid copying long explanations from the course.

---

### 3. Focus on what matters

Do not write every detail from the episode.

Write only:

- The main idea
- The rule or pattern being taught
- The part you are likely to forget
- The thing you can use in a real project

---

### 4. Include context: why or when

Help yourself remember *where* to apply the lesson.

Add a brief "when/why" clue so you can quickly decide if this applies later.

Good:

```md
- Use small controllers to keep each method testable and easy to change.
- Use migrations to track database schema changes in version control.
```

Avoid:

```md
- Controllers are small.
- Migrations exist.
```

---

### 5. Write lessons as retrievable answers

When you write a lesson, imagine someone asking you the inverse question.

This builds active recall — you can later cover the example and test yourself.

Good (retrievable):

```md
- Use small controllers to keep each method testable and easy to change.
(Answer to: "Why should controllers be small?")
```

Works but less retrievable:

```md
- Controllers should be small.
(Question is vague)
```

---

## Rules for Examples

### 1. Keep examples minimal

Show only the code that demonstrates the lesson. Strip everything else.

Good:

```php
Route::get('/posts', PostController::class);
```

Avoid:

```php
// 20 lines of boilerplate that don't relate to the lesson
```

---

### 2. Use real-world snippets, not toy examples

Prefer code you would actually write in a project. Abstract examples like `foo()` and `bar()` are harder to recall later.

Good:

```php
Post::where('published', true)->latest()->paginate(10);
```

Avoid:

```php
Model::doSomething()->doAnotherThing();
```

---

### 3. Show before/after when teaching refactoring

If the lesson is about improving existing code, show both states. This builds pattern recognition.

```php
// Before
public function index()
{
    $posts = Post::all();
    return view('posts.index', compact('posts'));
}

// After — using a resource
public function index()
{
    return PostResource::collection(Post::paginate(10));
}
```

---

### 4. Add a comment only when the key point is not obvious

Use a short comment to highlight what the lesson is about. Do not comment every line.

Good:

```php
// The route model binding automatically resolves Post from the URL
public function show(Post $post) { }
```

Avoid:

```php
// This is a controller method
// It takes a Post
// It returns a view
public function show(Post $post) { }
```

---

### 5. Keep snippets copy-paste ready

When possible, use code that works if pasted into a real project. This makes your notes a reference you can reuse.

---

### 6. Use inline code for short references

When mentioning a method, class, or function in a lesson bullet, wrap it in backticks so it stands out:

```md
- Use `PostResource::collection()` to transform a collection of models into a JSON-compatible format.
```

## Formatting Rules

These ensure your notes support recall and recognition:

### 1. Use pair-style bullets (lesson + code block)

This structure is enforced by "The Structure" section above:

````md
- **Lesson text here.**
  ```php
  // Example code
  ```
````

### 2. Write short, not long

Avoid paragraphs and multi-line explanation. Use bullets. This supports scannability and recall.

### 3. Use code block language tags

Always specify the language: `php`, `bash`, `js`, `env`, `sql`, etc. Syntax highlighting helps recognition.

### 4. Make notes readable in under one minute

A solid episode should be scannable and reviewable in 60 seconds. This supports spaced repetition and active recall.

---

## Golden Rule

**Write the lesson, show the matching example immediately, and move on.**

Stop here. You have all the essentials.

