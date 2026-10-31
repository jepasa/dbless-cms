<?php
/** Expected cache I/O failure; isolated from content validation and application errors. */
declare(strict_types=1);
namespace DBLESSCMS;
final class CacheFailure extends \RuntimeException {}
