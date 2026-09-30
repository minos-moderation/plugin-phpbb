<?php

namespace Symfony\Component\Routing\Generator;

/**
 * Stub of Symfony's URL generator interface: its reference types.
 */
interface UrlGeneratorInterface
{
	const ABSOLUTE_URL = 0;
	const ABSOLUTE_PATH = 1;
	const RELATIVE_PATH = 2;
	const NETWORK_PATH = 3;
}
