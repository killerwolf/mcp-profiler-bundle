# Plan: MCP Server Multi-App Support

**Objective:** Modify `RunMCPServerCommand.php` and its associated tool classes (`ProfilerList`, `ProfilerGetAllCollectorByToken`, `ProfilerGetOneCollectorByToken`, `ProfilerGetByTokenTool`) to support multiple Symfony applications based on `app_id` directory structures in the cache, similar to `ProfilerCommand.php`.

## Phase 1: Refactor `RunMCPServerCommand.php`

1.  **Modify Constructor:**
    *   Change the constructor signature from `__construct(?Profiler $profiler, ParameterBagInterface $parameterBag)` to `__construct(string $cacheDir, string $environment, ParameterBagInterface $parameterBag)`.
    *   Remove the `$profiler` property.
    *   Add private properties `$cacheDir` and `$environment` and store the injected values.
2.  **Modify `callTool` Method:**
    *   Remove the check `if (!$this->profiler ...)` as the profiler is no longer directly injected here. The tool classes themselves will handle cases where profiler data might be inaccessible.
    *   Before the `match` statement, derive the base cache directory and environment name:
        ```php
        $baseCacheDir = dirname(dirname($this->cacheDir)); // e.g., /path/to/project/var/cache
        $envName = $this->environment; // Use the injected environment name
        ```
    *   Update the instantiation of each tool class within the `match` statement to pass the `$baseCacheDir` and `$envName` instead of `$this->profiler`:
        *   `'profiler:list' => (new ProfilerList($baseCacheDir, $envName, $this->parameterBag))->execute(...)`
        *   `'profiler:get_collectors' => (new ProfilerGetAllCollectorByToken($baseCacheDir, $envName))->execute(...)`
        *   `'profiler:get_collector' => (new ProfilerGetOneCollectorByToken($baseCacheDir, $envName))->execute(...)`
        *   `'profiler:get_by_token' => (new ProfilerGetByTokenTool($baseCacheDir, $envName, $this->parameterBag))->execute(...)`

## Phase 2: Refactor Tool Classes

For each tool class (`ProfilerList`, `ProfilerGetAllCollectorByToken`, `ProfilerGetOneCollectorByToken`, `ProfilerGetByTokenTool`):

1.  **Modify Constructor:**
    *   Update the constructor signature to accept `$baseCacheDir` (string) and `$environment` (string). Keep `ParameterBagInterface` where applicable (`ProfilerList`, `ProfilerGetByTokenTool`).
    *   Remove the `$profiler` property.
    *   Add private properties `$baseCacheDir` and `$environment` and store the injected values.
2.  **Modify `execute` Method:**
    *   **Remove** the initial check `if (!$this->profiler)`.
    *   **Implement Multi-App Logic:** Add the core logic from `ProfilerCommand.php` to find and iterate over application-specific profiler directories:
        *   Use `Symfony\Component\Finder\Finder` to find directories matching `*_*` within `$this->baseCacheDir`.
        *   Inside the loop for each found `appIdDir`:
            *   Construct the path to the specific profiler directory: `$profilerDir = $appIdDir->getRealPath() . '/' . $this->environment . '/profiler';`
            *   Check if `$profilerDir` exists (`is_dir`).
            *   Create a `Symfony\Component\HttpKernel\Profiler\FileProfilerStorage` instance: `$storage = new FileProfilerStorage('file:' . $profilerDir);`
            *   Create a temporary `Symfony\Component\HttpKernel\Profiler\Profiler` instance: `$tempProfiler = new Profiler($storage);`
            *   Extract the `appId` from `$appIdDir->getFilename()`.
    *   **Adapt Tool-Specific Functionality:**
        *   **`ProfilerList`:**
            *   Initialize an empty array `$allProfiles = []`.
            *   Inside the loop, use `$tempProfiler->find(...)` to get tokens for the current app.
            *   Load each profile using `$tempProfiler->loadProfile($token['token'])`.
            *   Store the loaded profile *and* its corresponding `$appId` in the `$allProfiles` array (e.g., `['appId' => $appId, 'profile' => $profile]`).
            *   After the loop, sort `$allProfiles` by time (descending).
            *   Slice the array using the `$limit`.
            *   Format the results into the desired array structure, making sure to include the `appId` for each entry.
            *   Return the JSON encoded result.
        *   **`ProfilerGetAllCollectorByToken`, `ProfilerGetOneCollectorByToken`, `ProfilerGetByTokenTool`:**
            *   Initialize `$profile = null; $foundAppId = null;`.
            *   Inside the loop, after creating `$storage`, check if the requested `$token` exists in that storage: `if ($storage->read($token))`.
            *   If it exists:
                *   Create the `$tempProfiler`.
                *   Load the profile: `$profile = $tempProfiler->loadProfile($token);`
                *   Store the `$foundAppId`.
                *   `break;` out of the loop.
            *   After the loop, check if `$profile` was found.
            *   If yes, proceed with the original logic of the tool (getting all collectors, getting one collector, getting basic data + all collectors) using the found `$profile`. For `ProfilerGetByTokenTool`, add the `$foundAppId` to the basic profile data returned.
            *   If no profile was found, return the appropriate "not found" error JSON.

## Phase 3: Update Service Configuration (Conceptual)

*   The dependency injection configuration (likely in `config/services.php` or a bundle-specific configuration file) for the `Killerwolf\MCPProfilerBundle\Command\RunMCPServerCommand` service needs to be updated.
*   Instead of injecting `@profiler`, it should inject the string parameters `%kernel.cache_dir%` and `%kernel.environment%`.

## Mermaid Diagram

```mermaid
graph TD
    Start[Start Task: Multi-App MCP Server] --> ModServerCmd[Phase 1: Modify RunMCPServerCommand.php];
    ModServerCmd --> ModServerConst[Update Constructor: Inject cacheDir, environment];
    ModServerCmd --> ModServerCallTool[Update callTool: Derive paths, Instantiate Tools w/ new params];

    ModServerCallTool --> ModToolClasses[Phase 2: Modify Tool Classes];

    subgraph Phase 2: Modify Tool Classes
        direction LR
        ModToolConst[Update Constructors: Accept baseCacheDir, environment] --> ModToolExec[Update execute Methods];
        ModToolExec --> ImplMultiApp[Implement Multi-App Discovery Finder, Loop];
        ImplMultiApp --> CreateTemp[Create Temp Storage & Profiler per App];
        CreateTemp --> AdaptLogic[Adapt Core Logic];

        subgraph Adapt Core Logic
            direction TB
            ListLogic[ProfilerList: Aggregate profiles + appId, Sort, Limit]
            GetLogic[ProfilerGet*ByToken: Search apps for token, Load profile]
        end
    end

    ModToolClasses --> UpdateDI[Phase 3: Update Service Configuration];
    UpdateDI --> InjectParams[Inject %kernel.cache_dir%, %kernel.environment% into RunMCPServerCommand];

    InjectParams --> End[Plan Complete];

    style Start fill:#lightgrey,stroke:#333,stroke-width:2px
    style End fill:#lightgrey,stroke:#333,stroke-width:2px