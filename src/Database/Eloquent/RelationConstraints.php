<?php

namespace Spawn\Laravel\Database\Eloquent;

use Composer\Autoload\ClassLoader;

/**
 * Gives Eloquent's relation-constraints flag coroutine-safe semantics.
 *
 * `Relation::$constraints` is a static property that `noConstraints()` switches off around
 * a callback and restores from a captured value. Class statics live per worker thread, so
 * every coroutine of a worker shares that one flag, and the callback yields whenever a model
 * boots against config or cache inside it. Two things then break: overlapping windows restore
 * each other's captured value and pin the flag to false for the life of the worker, and while
 * it is false every relation built by every sibling coroutine comes out without its
 * `where foreign_key = ?` — the whole table, and no exception to say so.
 *
 * Six classes are rewritten at autoload time rather than shipped as copies: they differ
 * between Laravel 12 and 13 and change within a patch series, and frozen copies would swallow
 * those changes without saying so. `Relation` loses its captured save and restore; the five
 * `addConstraints()` implementations ask a method instead of reading the property; everything
 * else is Laravel's own.
 *
 * The rewritten classes behave exactly like Laravel's own until startServing() is called, so
 * artisan, queue workers and migrations keep the framework's semantics and never touch the
 * coroutine machinery.
 *
 * Installed from bootstrap.php, which Composer includes while it is still loading files and
 * therefore before an application can touch Eloquent.
 */
final class RelationConstraints
{
    public const RELATION_CLASS = 'Illuminate\\Database\\Eloquent\\Relations\\Relation';

    /** The classes whose `addConstraints()` reads the flag, each as a single `if`. */
    private const READER_CLASSES = [
        'Illuminate\\Database\\Eloquent\\Relations\\BelongsTo',
        'Illuminate\\Database\\Eloquent\\Relations\\BelongsToMany',
        'Illuminate\\Database\\Eloquent\\Relations\\HasOneOrMany',
        'Illuminate\\Database\\Eloquent\\Relations\\HasOneOrManyThrough',
        'Illuminate\\Database\\Eloquent\\Relations\\MorphOneOrMany',
    ];

    /** Prefix of the cached class files, and the mark that says the patch is in place. */
    private const CACHE_PREFIX = 'spawn-relation-';

    private const READER_ANCHOR = 'if (static::$constraints) {';

    private const READER_REPLACEMENT = 'if (static::spawnConstraintsEnabled()) {';

    /**
     * Redirect the autoloader to coroutine-safe copies of the six Eloquent classes.
     *
     * Returns false when nothing was installed, which is not an error: the application then
     * keeps Laravel's own classes and behaves as it does without this adapter. status() names
     * the reason. Either all six are redirected or none is.
     *
     * @param  string|null  $cacheDirectory  Where the rewritten classes are kept; the system
     *                                       temporary directory when null.
     */
    public static function install(?string $cacheDirectory = null): bool
    {
        if (self::patchedFile(self::RELATION_CLASS) !== null) {
            return true;
        }

        if (self::refusal() !== null) {
            return false;
        }

        $rewritten = self::rewriteAll();

        if ($rewritten === null) {
            return false;
        }

        $directory = $cacheDirectory ?? sys_get_temp_dir();
        $files = [];

        foreach ($rewritten as $class => $source) {
            $file = self::cache($source, $directory);

            if ($file === null) {
                return false;
            }

            $files[$class] = $file;
        }

        foreach (ClassLoader::getRegisteredLoaders() as $loader) {
            $loader->addClassMap($files);
        }

        return true;
    }

    /**
     * Tell the rewritten classes that this thread is serving requests concurrently.
     *
     * Until this call they hold the flag exactly where Laravel holds it, which is what a
     * one-request-at-a-time process wants; after it, each coroutine answers from its own
     * context. Returns false when the patch is not in place, and the caller — worker
     * start-up — is expected to say so out loud rather than serve quietly without it.
     */
    public static function startServing(): bool
    {
        if (! method_exists(self::RELATION_CLASS, 'spawnStartServing')) {
            return false;
        }

        (self::RELATION_CLASS)::spawnStartServing();

        return true;
    }

    /**
     * Whether the patch is in place, and when it is not, what stands in the way.
     *
     * Derived from the autoloader and the environment rather than remembered from install():
     * a mutable static is the very thing this class works around, and a context write made
     * while Composer is still including files does not survive into the request that asks.
     */
    public static function status(): string
    {
        $file = self::patchedFile(self::RELATION_CLASS);

        if ($file !== null) {
            return "installed: $file";
        }

        return 'not installed: '.(self::refusal() ?? 'the rewritten classes could not be cached on disk');
    }

    /**
     * The reason the patch must not be installed, or null when nothing stands in the way.
     */
    private static function refusal(): ?string
    {
        if (! function_exists('Async\\coroutine_context')) {
            return 'the true_async extension is not loaded';
        }

        if (getenv('SPAWN_RELATION_PATCH') === '0') {
            return 'switched off through SPAWN_RELATION_PATCH=0';
        }

        foreach (self::classes() as $class) {
            if (class_exists($class, false) && self::patchedFile($class) === null) {
                return "$class was already loaded before the patch could be installed";
            }
        }

        if (self::rewriteAll() === null) {
            return 'the Eloquent sources no longer carry the statements this patch replaces';
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private static function classes(): array
    {
        return array_merge([self::RELATION_CLASS], self::READER_CLASSES);
    }

    /**
     * Every class rewritten, keyed by name, or null when any one of them cannot be.
     *
     * All or nothing: a `Relation` that counts windows next to a reader still testing the
     * raw property would answer differently in the same query.
     *
     * @return array<string, string>|null
     */
    private static function rewriteAll(): ?array
    {
        $rewritten = [];

        foreach (self::classes() as $class) {
            $original = self::locateOriginal($class);

            if ($original === null || ! is_readable($original)) {
                return null;
            }

            $source = (string) file_get_contents($original);

            $result = $class === self::RELATION_CLASS
                ? self::rewriteRelation($source)
                : self::rewriteReader($source);

            if ($result === null) {
                return null;
            }

            $rewritten[$class] = $result;
        }

        return $rewritten;
    }

    /**
     * The rewritten class the autoloader would include, or null while Laravel's own stands.
     */
    private static function patchedFile(string $class): ?string
    {
        foreach (ClassLoader::getRegisteredLoaders() as $loader) {
            $file = $loader->findFile($class);

            if (is_string($file) && str_starts_with(basename($file), self::CACHE_PREFIX)) {
                return $file;
            }
        }

        return null;
    }

    private static function locateOriginal(string $class): ?string
    {
        foreach (ClassLoader::getRegisteredLoaders() as $loader) {
            $file = $loader->findFile($class);

            if (is_string($file) && is_file($file) && ! str_starts_with(basename($file), self::CACHE_PREFIX)) {
                return $file;
            }
        }

        return null;
    }

    /**
     * Take the writers off the property, or return null when the source has moved on.
     *
     * `noConstraints()`, and in Laravel 13 `withoutConstraints()` and `withConstraints()`,
     * stop assigning the flag directly and go through a stack, so the value a window found is
     * restored by the window that leaves last rather than by whichever caller captured it.
     * Every assignment in the file has to be one this method recognises: an unknown writer
     * left in place beside a rewritten restore would unbalance the stack.
     */
    private static function rewriteRelation(string $source): ?string
    {
        $enterFalse = substr_count($source, 'static::$constraints = false;');
        $enterTrue = substr_count($source, 'static::$constraints = true;');
        $leave = substr_count($source, 'static::$constraints = $previous;');

        if ($enterFalse < 1 || $enterFalse + $enterTrue !== $leave) {
            return null;
        }

        if (substr_count($source, 'static::$constraints =') !== $enterFalse + $enterTrue + $leave) {
            return null;
        }

        $source = str_replace(
            ['static::$constraints = false;', 'static::$constraints = true;', 'static::$constraints = $previous;'],
            ['static::spawnEnterWindow(false);', 'static::spawnEnterWindow(true);', 'static::spawnLeaveWindow();'],
            $source
        );

        $declaration = '    protected static $constraints = true;';

        if (substr_count($source, $declaration) !== 1) {
            return null;
        }

        return str_replace($declaration, $declaration."\n".self::MEMBERS, $source);
    }

    private static function rewriteReader(string $source): ?string
    {
        if (substr_count($source, 'static::$constraints') !== 1
            || substr_count($source, self::READER_ANCHOR) !== 1) {
            return null;
        }

        return str_replace(self::READER_ANCHOR, self::READER_REPLACEMENT, $source);
    }

    /**
     * Write a rewritten class where the autoloader can include it, named after its own
     * content so that two Laravel versions, or two applications, never share one file.
     *
     * The content is compared before the path is handed back on every route through this
     * method: the directory is shared and world-writable, so a file already sitting at the
     * name is treated as somebody else's until it proves to be byte-for-byte ours.
     */
    private static function cache(string $source, string $directory): ?string
    {
        $file = rtrim($directory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR
            .self::CACHE_PREFIX.hash('sha256', $source).'.php';

        if (self::holds($file, $source)) {
            return $file;
        }

        $temporary = $file.'.'.bin2hex(random_bytes(8));

        if (@file_put_contents($temporary, $source) !== strlen($source)) {
            @unlink($temporary);

            return null;
        }

        if (@rename($temporary, $file)) {
            return $file;
        }

        @unlink($temporary);

        // A sibling thread renaming the same content into place loses the race and lands here.
        return self::holds($file, $source) ? $file : null;
    }

    /**
     * Whether the path is a plain file this process may include and holds exactly this source.
     */
    private static function holds(string $file, string $source): bool
    {
        return ! is_link($file) && is_file($file) && @file_get_contents($file) === $source;
    }

    /**
     * The members injected next to $constraints.
     *
     * $spawnWindows keeps the property itself meaningful for anything that still reads it
     * directly, and it is thread state like the property. The decision the rewritten readers
     * act on comes from the coroutine's own context, which nothing inherits, so a window left
     * open by a coroutine that was cancelled dies with it.
     */
    private const MEMBERS = <<<'PHP'

    /** Values of the windows open in this worker thread, and what the first of them found. */
    protected static $spawnWindows = [];

    protected static $spawnBaseline = true;

    /** False until worker start-up says requests are served concurrently in this thread. */
    protected static $spawnServing = false;

    /** Key of the current coroutine's own stack of window values: list<bool>. */
    private const SPAWN_OWN_WINDOWS = 'spawn.relation.own_windows';

    public static function spawnStartServing()
    {
        static::$spawnServing = true;
    }

    protected static function spawnEnterWindow($constraints)
    {
        if (static::$spawnWindows === []) {
            static::$spawnBaseline = static::$constraints;
        }

        static::$spawnWindows[] = (bool) $constraints;
        static::$constraints = $constraints;

        if (! static::$spawnServing) {
            return;
        }

        $context = \Async\coroutine_context();
        $own = $context->findLocal(self::SPAWN_OWN_WINDOWS) ?? [];
        $own[] = (bool) $constraints;
        $context->set(self::SPAWN_OWN_WINDOWS, $own, true);
    }

    protected static function spawnLeaveWindow()
    {
        array_pop(static::$spawnWindows);

        static::$constraints = static::$spawnWindows === []
            ? static::$spawnBaseline
            : end(static::$spawnWindows);

        if (! static::$spawnServing) {
            return;
        }

        $context = \Async\coroutine_context();
        $own = $context->findLocal(self::SPAWN_OWN_WINDOWS) ?? [];
        array_pop($own);
        $context->set(self::SPAWN_OWN_WINDOWS, $own, true);
    }

    /**
     * Whether the relation being built must add its own constraints.
     *
     * While requests are served concurrently the answer is the innermost window this
     * coroutine opened itself, and true outside any of its own: a window belongs to the
     * coroutine that opened it, and the shared property is no evidence about this one.
     */
    protected static function spawnConstraintsEnabled()
    {
        if (! static::$spawnServing) {
            return static::$constraints;
        }

        $own = \Async\coroutine_context()->findLocal(self::SPAWN_OWN_WINDOWS) ?? [];

        return $own === [] ? true : end($own);
    }
PHP;
}
