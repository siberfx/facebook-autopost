<?php

declare(strict_types=1);

namespace Siberfx\FacebookAutopost\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Siberfx\FacebookAutopost\Contracts\ShareableOnFacebook;
use Siberfx\FacebookAutopost\FacebookManager;
use Siberfx\FacebookAutopost\Models\FacebookShare;
use Throwable;

final class ShareCommand extends Command
{
    protected $signature = 'facebook:share
        {model : Morph alias or model class}
        {id : Primary key}
        {--force : Share again even if it was already posted}';

    protected $description = 'Share a model on Facebook now';

    public function handle(FacebookManager $facebook): int
    {
        $type = $this->stringArgument('model');
        $class = Relation::getMorphedModel($type) ?? $type;

        if (! class_exists($class) || ! is_subclass_of($class, Model::class) || ! is_subclass_of($class, ShareableOnFacebook::class)) {
            $this->components->error("[{$type}] is not a shareable model.");

            return self::FAILURE;
        }

        $id = $this->stringArgument('id');

        /** @var (Model&ShareableOnFacebook)|null $model */
        $model = $class::query()->find($id);

        if ($model === null) {
            $this->components->error("Record [{$id}] not found.");

            return self::FAILURE;
        }

        if (! $this->option('force') && $facebook->wasPosted($model)) {
            $this->components->warn('This was already posted to Facebook. Use --force to share it again.');

            return self::FAILURE;
        }

        try {
            $post = $facebook->share($model, FacebookShare::TRIGGER_CONSOLE);
        } catch (Throwable $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->components->info($post->post_id !== null
            ? "Shared on Facebook: {$post->post_id}"
            : 'Shared on Facebook (Facebook returned no post id).');

        return self::SUCCESS;
    }

    private function stringArgument(string $name): string
    {
        $value = $this->argument($name);

        return is_scalar($value) ? (string) $value : '';
    }
}
