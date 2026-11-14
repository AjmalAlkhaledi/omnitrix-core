<?php

declare(strict_types=1);

namespace Omnitrix\Console\Commands;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

use function str_replace;

#[AsCommand(name: 'make:middleware', description: 'Create a middleware class in src/Middleware')]
final class MakeMiddlewareCommand extends MakeCommand
{
    private const string TEMPLATE = <<<'PHP'
        <?php

        declare(strict_types=1);

        namespace App\Middleware{{ namespace }};

        use Omnitrix\Http\Middleware\MiddlewareInterface;
        use Omnitrix\Http\Middleware\Next;
        use Omnitrix\Http\Request;
        use Omnitrix\Http\Response;

        final class {{ class }} implements MiddlewareInterface
        {
            public function process(Request $request, Next $next): Response
            {
                // Before the handler: inspect the request or answer early.
                $response = $next($request);

                // After the handler: adjust the response.
                return $response;
            }
        }

        PHP;

    protected function configure(): void
    {
        $this->configureName('Middleware class name, e.g. RequireApiKey');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = $this->name($input);
        [$namespace, $class] = self::split($name);

        return $this->write($input, $output, $this->console()->path('src', 'Middleware', str_replace('\\', '/', $name) . '.php'), self::TEMPLATE, ['namespace' => $namespace, 'class' => $class]) ? self::SUCCESS : self::FAILURE;
    }
}
