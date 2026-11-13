<?php

declare(strict_types=1);

namespace Omnitrix\Core\Http\Server;

use LogicException;

/** Status, headers or cookies were modified after the response started streaming. */
final class HeadersAlreadySentException extends LogicException {}
