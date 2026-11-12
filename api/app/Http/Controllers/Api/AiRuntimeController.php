<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\AiPassJob;
use App\Models\Account;
use App\Services\Ai\AiSettings;
use App\Services\Ai\ModelRuntime;
use App\Services\Ai\OllamaClient;
use App\Services\Ai\Processing\AiPass;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * "Process new mail" (one AI pass on the user's click) and the small status line in the
 * sidebar: progress, models in RAM, and "Unload" for the ones Sovera loaded.
 */
class AiRuntimeController extends Controller
{
    /** Polled by the UI: what waits, what runs, which models are in RAM. Cheap, never loads a model. */
    public function status(): JsonResponse
    {
        $settings = AiSettings::load();
        $pending = ['threads' => 0, 'index' => 0, 'style' => false, 'knowledge' => 0];
        $pass = null;
        foreach ($this->accounts() as $account) {
            $p = AiPass::pending($account);
            $pending = [
                'threads' => $pending['threads'] + $p['threads'],
                'index' => $pending['index'] + $p['index'],
                'style' => $pending['style'] || $p['style'],
                'knowledge' => $pending['knowledge'] + $p['knowledge'],
            ];
            $state = AiPass::state($account->id);
            if ($state && ($pass === null || AiPass::running($account->id))) {
                $pass = array_diff_key($state, ['tried' => true]) + ['running' => AiPass::running($account->id)];
            }
        }

        return response()->json(['data' => [
            'ready' => $settings->isReady(),
            'pending' => $pending,
            'has_work' => AiPass::hasWork($pending),
            'pass' => $pass,
            'models' => $settings->isReady() ? ModelRuntime::loaded($settings) : [],
        ]]);
    }

    /** "Process new mail": one pass per account, unless one is already running. */
    public function process(): JsonResponse
    {
        $settings = AiSettings::load();
        if (! $settings->isReady()) {
            return response()->json(['message' => 'Local AI is not configured. Open "Configure AI" and pick an Ollama model.', 'ai_unavailable' => true], 503);
        }
        $queued = 0;
        foreach ($this->accounts() as $account) {
            if (AiPass::running($account->id)) {
                continue;
            }
            AiPass::put($account->id, ['state' => 'queued', 'error' => null, 'done' => 0, 'total' => 0]);
            AiPassJob::dispatch($account->id);
            $queued++;
        }

        return response()->json(['queued' => $queued], 202);
    }

    /**
     * "Unload": take a model out of RAM now. Only one Sovera loaded (another app's model is
     * not ours to stop) and not while processing is using it. Models never leave on their own.
     */
    public function unload(Request $request): JsonResponse
    {
        $data = $request->validate(['model' => ['required', 'string', 'max:200']]);
        $settings = AiSettings::load();
        $model = collect(ModelRuntime::loaded($settings))->firstWhere('name', $data['model']);
        if ($model === null) {
            return response()->json(['unloaded' => false, 'message' => 'That model is not in memory.'], 200);
        }
        if (! $model['ours']) {
            return response()->json(['message' => 'Another app is using this model; Sovera Mail leaves it alone.'], 409);
        }
        foreach ($this->accounts() as $account) {
            if (AiPass::running($account->id)) {
                return response()->json(['message' => 'Processing is still using the model. Unload it when it is done.'], 409);
            }
        }
        (new OllamaClient($settings->baseUrl))->unload($data['model']);

        return response()->json(['unloaded' => true]);
    }

    /** @return iterable<Account> */
    private function accounts(): iterable
    {
        return Account::query()->get()->reject(fn (Account $a) => $a->isDisconnected());
    }
}
