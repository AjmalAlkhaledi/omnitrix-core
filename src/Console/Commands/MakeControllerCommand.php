<?php

declare(strict_types=1);

namespace Omnitrix\Console\Commands;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use function is_string;
use function str_replace;

#[AsCommand(name: 'make:controller', description: 'Create a controller class in src/Controllers')]
final class MakeControllerCommand extends MakeCommand
{
    private const string INVOKABLE = <<<'PHP'
        <?php

        declare(strict_types=1);

        namespace App\Controllers{{ namespace }};

        use Omnitrix\Http\Request;
        use Omnitrix\Http\Response;

        final class {{ class }}
        {
            public function __invoke(Request $request): Response
            {
                return Response::json(['message' => 'Hello from {{ class }}']);
            }
        }

        PHP;

    private const string RESOURCE = <<<'PHP'
        <?php

        declare(strict_types=1);

        namespace App\Controllers{{ namespace }};

        use Omnitrix\Http\Request;
        use Omnitrix\Http\Response;

        final class {{ class }}
        {
            public function index(Request $request): Response
            {
                return Response::json(['data' => []]);
            }

            public function show(Request $request): Response
            {
                return Response::json(['data' => ['id' => $request->routeInt('id')]]);
            }

            public function store(Request $request): Response
            {
                $data = $request->validate([
                    // 'name' => 'required|string|max:255',
                ]);

                return Response::json(['data' => $data], 201);
            }

            public function update(Request $request): Response
            {
                $data = $request->validate([
                    // 'name' => 'string|max:255',
                ]);

                return Response::json(['data' => ['id' => $request->routeInt('id')] + $data]);
            }

            public function destroy(Request $request): Response
            {
                return Response::noContent();
            }
        }

        PHP;

    private const string MODEL_RESOURCE = <<<'PHP'
        <?php

        declare(strict_types=1);

        namespace App\Controllers{{ namespace }};

        use App\Models\{{ model }};
        use Omnitrix\Database\Paginator;
        use Omnitrix\Http\Request;
        use Omnitrix\Http\Response;

        final class {{ class }}
        {
            /** @return Paginator<{{ model }}> */
            public function index(Request $request): Paginator
            {
                $page = (int) ($request->queryParam('page') ?? 1);

                return {{ model }}::query()->latest()->paginate(perPage: 20, page: $page);
            }

            public function show(Request $request): {{ model }}
            {
                return {{ model }}::findOrFail($request->routeInt('id'));
            }

            public function store(Request $request): Response
            {
                $data = $request->validate([
                    // 'name' => 'required|string|max:255',
                ]);

                return Response::json({{ model }}::create($data), 201);
            }

            public function update(Request $request): {{ model }}
            {
                $model = {{ model }}::findOrFail($request->routeInt('id'));
                $model->update($request->validate([
                    // 'name' => 'string|max:255',
                ]));

                return $model;
            }

            public function destroy(Request $request): Response
            {
                {{ model }}::findOrFail($request->routeInt('id'))->delete();

                return Response::noContent();
            }
        }

        PHP;

    protected function configure(): void
    {
        $this->configureName('Controller class name, e.g. UserController or Admin/UserController');
        $this->addOption('resource', 'r', InputOption::VALUE_NONE, 'Generate index, show, store, update and destroy actions')
            ->addOption('model', 'm', InputOption::VALUE_REQUIRED, 'Generate resource actions backed by this model (implies --resource)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = $this->name($input);
        [$namespace, $class] = self::split($name);
        $model = $input->getOption('model');
        $template = is_string($model) && $model !== '' ? self::MODEL_RESOURCE : ($input->getOption('resource') === true ? self::RESOURCE : self::INVOKABLE);
        $path = $this->console()->path('src', 'Controllers', str_replace('\\', '/', $name) . '.php');
        if (!$this->write($input, $output, $path, $template, ['namespace' => $namespace, 'class' => $class, 'model' => is_string($model) ? $model : ''])) {
            return self::FAILURE;
        }
        if ($template !== self::INVOKABLE) {
            $fqcn = 'App\\Controllers\\' . $name;
            $output->writeln("\nRegister the routes, for example in routes/api.php:\n");
            $output->writeln("    \$router->get('/items', [\\{$fqcn}::class, 'index']);");
            $output->writeln("    \$router->get('/items/{id:\\d+}', [\\{$fqcn}::class, 'show']);");
            $output->writeln("    \$router->post('/items', [\\{$fqcn}::class, 'store']);");
            $output->writeln("    \$router->put('/items/{id:\\d+}', [\\{$fqcn}::class, 'update']);");
            $output->writeln("    \$router->delete('/items/{id:\\d+}', [\\{$fqcn}::class, 'destroy']);");
        }

        return self::SUCCESS;
    }
}
