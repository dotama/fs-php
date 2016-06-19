<?php

interface RequestAuthenticator {
	// Authenticate the given request represented by URL, query params and headers.
	// In case the user can be identified, an ID must be returned.
	//
	// @param $url string
	// @param $query array
	// @param $headers array
	//
	// @return string|null
	function authenticate($url, $query, $headers);
}
