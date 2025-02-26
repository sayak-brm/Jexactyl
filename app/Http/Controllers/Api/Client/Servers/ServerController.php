<?php

namespace Pterodactyl\Http\Controllers\Api\Client\Servers;

use Illuminate\Http\Response;
use Pterodactyl\Models\Server;
use Illuminate\Http\JsonResponse;
use Pterodactyl\Services\Servers\ServerDeletionService;
use Pterodactyl\Repositories\Eloquent\SubuserRepository;
use Pterodactyl\Transformers\Api\Client\ServerTransformer;
use Pterodactyl\Services\Servers\GetUserPermissionsService;
use Pterodactyl\Http\Controllers\Api\Client\ClientApiController;
use Pterodactyl\Http\Requests\Api\Client\Servers\GetServerRequest;
use Pterodactyl\Http\Requests\Api\Client\Servers\DeleteServerRequest;

class ServerController extends ClientApiController
{
    private SubuserRepository $repository;
    private ServerDeletionService $deletionService;
    private GetUserPermissionsService $permissionsService;

    /**
     * ServerController constructor.
     */
    public function __construct(
        GetUserPermissionsService $permissionsService,
        ServerDeletionService $deletionService,
        SubuserRepository $repository,
    )
    {
        parent::__construct();

        $this->repository = $repository;
        $this->deletionService = $deletionService;
        $this->permissionsService = $permissionsService;
    }

    /**
     * Transform an individual server into a response that can be consumed by a
     * client using the API.
     */
    public function index(GetServerRequest $request, Server $server): array
    {
        return $this->fractal->item($server)
            ->transformWith($this->getTransformer(ServerTransformer::class))
            ->addMeta([
                'is_server_owner' => $request->user()->id === $server->owner_id,
                'user_permissions' => $this->permissionsService->handle($server, $request->user()),
            ])
            ->toArray();
    }

    /**
     * Deletes the requested server via the API and
     * returns the resources to the authenticated user.
     * 
     * @throws DisplayException
     */
    public function delete(DeleteServerRequest $request, Server $server): JsonResponse
    {
        $user = $request->user();

        if ($user->id != $server->owner_id) {
            throw new DisplayException('You are not authorized to perform this action.');
        };

        try {
            $this->deletionService->returnResources(true)->handle($server);
        } catch (DisplayException $ex) {
            throw new DisplayException('Unable to delete the server from the system.');
        }

        try {
            $user->update([
                'store_cpu' => $user->store_cpu + $server->cpu,
                'store_memory' => $user->store_memory + $server->memory,
                'store_disk' => $user->store_disk + $server->disk,
                'store_slots' => $user->store_slots + 1,
                'store_ports' => $user->store_ports + $server->allocation_limit,
                'store_backups' => $user->store_backups + $server->backup_limit,
                'store_databases' => $user->store_databases + $server->database_limit,
            ]);
        } catch (DisplayException $ex) {
            throw new DisplayException('Unable to add resources to user.');
        };

        return new JsonResponse([], Response::HTTP_NO_CONTENT);
    }
}
