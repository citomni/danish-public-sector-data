<?php
declare(strict_types=1);
/*
 * This file is part of the CitOmni framework.
 * Low overhead, high performance, ready for anything.
 *
 * For more information, visit https://github.com/citomni
 *
 * Copyright (c) 2012-present Lars Grove Mortensen
 * SPDX-License-Identifier: MIT
 *
 * For full copyright, trademark, and license information,
 * please see the LICENSE file distributed with this source code.
 */

namespace CitOmni\DanishPublicSectorData\Support;

use CitOmni\DanishPublicSectorData\Exception\InvalidResponseException;
use CitOmni\DanishPublicSectorData\Exception\RateLimitException;
use CitOmni\DanishPublicSectorData\Exception\RemoteServiceException;
use CitOmni\Infrastructure\Exception\CurlException;
use CitOmni\Kernel\App;

/**
 * SkatVatWebClient: Support class that runs SKAT's anonymous web lookup of VAT numbers.
 *
 * Lets the vatRegistration service check one CVR/SE number while SKAT offers no API for it. The
 * public page is no documented contract, so the client finds the search form anew on every lookup
 * instead of relying on its field names, and reads only explicit result texts. What a result means
 * for a caller belongs to VatRegistration.
 *
 * Rules:
 * - Never bypass a CAPTCHA, a login or another access control.
 * - Keep the session, its cookies and its jsessionid URLs inside one lookup.
 * - Report a registration state only when the page states it.
 *
 * @internal
 */
class SkatVatWebClient {

	/** App whose cfg and curl service the client uses. */
	private App $app;

	/** URL of SKAT's public VAT-number search page. */
	private string $searchUrl;

	/** Request timeout in seconds. */
	private int|float $timeout;

	/** Connect timeout in seconds. */
	private int|float $connectTimeout;

	/**
	 * Read and check the SKAT web cfg.
	 *
	 * @param  App  $app
	 * @throws \UnexpectedValueException  When cfg danish_public_sector_data.skat_vat_web has a search_url that is not an HTTPS URL, or a timeout that is not a finite number of at least 0.
	 */
	public function __construct(App $app) {
		$this->app = $app;

		$cfg = $this->app->cfg->danish_public_sector_data->skat_vat_web;
		$this->searchUrl = $this->httpsUrl((string)$cfg->search_url, 'search_url');
		$this->timeout = $this->normalizeTimeout($cfg->timeout, 'timeout');
		$this->connectTimeout = $this->normalizeTimeout($cfg->connect_timeout, 'connect_timeout');
	}

	/**
	 * Look up one CVR/SE number in SKAT's public VAT-number search.
	 *
	 * Runs the search in an anonymous session of its own: It opens the search page, submits the
	 * number in the search form and reads the result page.
	 *
	 * Behavior:
	 * - registered is true or false only when the result page says so explicitly, and null when the
	 *   page is not decisive.
	 * - verifiedOn is the verification date that the page shows, as YYYY-MM-DD, or null.
	 * - The cookie file of the session is removed again, also when the lookup fails.
	 *
	 * Typical usage:
	 *   $result = (new SkatVatWebClient($this->app))->lookup('12345678');
	 *
	 * @param  string  $registrationNumber  CVR/SE number of eight digits, already validated.
	 * @return array{registrationNumber:string,registered:?bool,verifiedOn:?string}
	 * @throws \CitOmni\DanishPublicSectorData\Exception\RateLimitException  When SKAT rate-limits a request.
	 * @throws \CitOmni\DanishPublicSectorData\Exception\RemoteServiceException  When the cookie file cannot be created, a request fails or SKAT answers with an unsuccessful status.
	 * @throws \CitOmni\DanishPublicSectorData\Exception\InvalidResponseException  When the search form cannot be found or used safely.
	 * @throws \UnexpectedValueException  When the search page has a form without an action and a URL that is not HTTPS.
	 */
	public function lookup(string $registrationNumber): array {
		$cookieStore = \tempnam(\sys_get_temp_dir(), 'citomni-skat-vat-');
		if ($cookieStore === false) {
			throw new RemoteServiceException('SKAT VAT lookup could not create an isolated cookie store.');
		}

		@\chmod($cookieStore, 0600);

		try {
			$initial = $this->request([
				'url' => $this->searchUrl,
				'method' => 'GET',
				'headers' => [
					'Accept: text/html,application/xhtml+xml',
				],
				'cookie_store' => $cookieStore,
				'follow_redirects' => true,
				'capture_info' => false,
			]);

			$effectiveUrl = $initial['effective_url'] ?? null;
			if (!\is_string($effectiveUrl) || $effectiveUrl === '') {
				throw new InvalidResponseException('SKAT VAT lookup did not expose the effective search URL.');
			}
			$effectiveUrl = self::withoutJsessionId($effectiveUrl);

			$form = $this->parseSearchForm((string)$initial['body'], $effectiveUrl, $registrationNumber);
			$submit = [
				'url' => $form['action'],
				'method' => $form['method'],
				'headers' => [
					'Accept: text/html,application/xhtml+xml',
				],
				'cookie_store' => $cookieStore,
				'follow_redirects' => true,
				'capture_info' => false,
				'referer' => $effectiveUrl,
			];

			if ($form['method'] === 'GET') {
				$submit['query'] = $form['fields'];
			} else {
				$submit['headers'][] = 'Content-Type: application/x-www-form-urlencoded';
				$submit['body'] = \http_build_query($form['fields'], '', '&', \PHP_QUERY_RFC3986);
			}

			$result = $this->request($submit);
			$text = $this->visibleText((string)$result['body']);

			return [
				'registrationNumber' => $registrationNumber,
				'registered' => $this->registrationState($text, $registrationNumber),
				'verifiedOn' => $this->verificationDate($text),
			];
		} finally {
			@\unlink($cookieStore);
		}
	}

	/**
	 * Send one request of the lookup with the cfg timeouts.
	 *
	 * @param  array<string,mixed>  $request  Request of the curl service.
	 * @return array<string,mixed>  Response of the curl service, with a successful status.
	 * @throws \CitOmni\DanishPublicSectorData\Exception\RateLimitException  When SKAT answers 429.
	 * @throws \CitOmni\DanishPublicSectorData\Exception\RemoteServiceException  When the request fails or SKAT answers with another unsuccessful status.
	 */
	private function request(array $request): array {
		$request += [
			'timeout' => $this->timeout,
			'connect_timeout' => $this->connectTimeout,
			'return_headers' => false,
		];

		try {
			$response = $this->app->curl->execute($request);
		} catch (CurlException) {
			throw new RemoteServiceException('SKAT VAT public web lookup transport failed.');
		}

		$statusCode = (int)$response['status_code'];
		if ($statusCode === 429) {
			throw new RateLimitException('SKAT VAT public web lookup rate limit exceeded.', $statusCode);
		}
		if (!$response['is_http_success']) {
			throw new RemoteServiceException('SKAT VAT public web lookup returned an unsuccessful HTTP response.', $statusCode);
		}

		return $response;
	}

	/**
	 * Find the CVR/SE search form and build its submission for one number.
	 *
	 * Behavior:
	 * - Uses the first form whose visible text contains cvr-/se-nummer, in any letter case.
	 * - Keeps the hidden fields, chooses the search by number among the radio buttons, puts the number
	 *   in the field that looks most like a CVR/SE field, and adds the search button when it has a
	 *   name.
	 * - The action is an absolute HTTPS URL without a jsessionid.
	 *
	 * @param  string  $html  Search page.
	 * @param  string  $effectiveUrl  URL of the search page after redirects, without a jsessionid.
	 * @param  string  $registrationNumber  CVR/SE number of eight digits.
	 * @return array{action:string,method:string,fields:array<string,string>}  method is GET or POST.
	 * @throws \CitOmni\DanishPublicSectorData\Exception\InvalidResponseException  When no such form exists, it uses another method, its number field cannot be found with confidence, or its action is not HTTPS.
	 * @throws \UnexpectedValueException  When the form has no action and the page URL is not HTTPS.
	 */
	private function parseSearchForm(string $html, string $effectiveUrl, string $registrationNumber): array {
		if (!\preg_match_all('~<form\b([^>]*)>(.*?)</form\s*>~isu', $html, $forms, \PREG_SET_ORDER)) {
			throw new InvalidResponseException('SKAT VAT lookup page does not contain a search form.');
		}

		foreach ($forms as $match) {
			$formBody = (string)$match[2];
			$formText = $this->visibleText($formBody);
			if (!self::containsFolded($formText, 'cvr-/se-nummer')) {
				continue;
			}

			$formAttrs = self::attributes((string)$match[1]);
			$method = \strtoupper($formAttrs['method'] ?? 'GET');
			if ($method !== 'GET' && $method !== 'POST') {
				throw new InvalidResponseException('SKAT VAT lookup uses an unsupported form method.');
			}

			$action = $this->resolveUrl($effectiveUrl, $formAttrs['action'] ?? '');
			$labels = $this->labels($formBody);
			$inputs = $this->inputs($formBody);
			$fields = [];
			$cvrField = null;
			$cvrScore = -1;
			$searchModeField = null;
			$searchModeValue = null;
			$searchModeScore = -1;

			foreach ($inputs as $input) {
				$name = $input['name'] ?? '';
				if ($name === '') {
					continue;
				}

				$type = \strtolower($input['type'] ?? 'text');
				if ($type === 'hidden') {
					$fields[$name] = $input['value'] ?? '';
					continue;
				}

				if ($type === 'radio' || $type === 'checkbox') {
					$id = $input['id'] ?? '';
					$label = $id !== '' ? ($labels[$id] ?? '') : '';

					if ($type === 'radio') {
						$choice = \strtolower($id . ' ' . $label);
						$score = 0;
						if (self::containsFolded($choice, 'cvr')) {
							$score += 100;
						}
						if (self::containsFolded($choice, 'se-nummer') || self::containsFolded($choice, 'se nummer')) {
							$score += 80;
						}
						if (self::containsFolded($choice, 'nummer')) {
							$score += 20;
						}

						if ($score === 0 && \array_key_exists('checked', $input)) {
							$group = \strtolower($name);
							if (self::containsFolded($group, 'cvr') && self::containsFolded($group, 'nummer')) {
								$score = 1;
							}
						}

						if ($score > $searchModeScore) {
							$searchModeScore = $score;
							$searchModeField = $name;
							$searchModeValue = $input['value'] ?? 'on';
						}
					} elseif (\array_key_exists('checked', $input)) {
						$fields[$name] = $input['value'] ?? 'on';
					}

					continue;
				}

				if (\in_array($type, ['submit', 'button', 'reset', 'image', 'file'], true)) {
					continue;
				}

				$id = $input['id'] ?? '';
				$label = $id !== '' ? ($labels[$id] ?? '') : '';
				$descriptor = \strtolower($name . ' ' . $id . ' ' . $label);
				$score = 0;
				if (self::containsFolded($descriptor, 'cvr')) {
					$score += 100;
				}
				if (self::containsFolded($descriptor, 'se-nummer') || self::containsFolded($descriptor, 'se nummer')) {
					$score += 80;
				}
				if (self::containsFolded($descriptor, 'nummer')) {
					$score += 20;
				}
				if (($input['maxlength'] ?? '') === '8') {
					$score += 30;
				}
				if (\strtolower($input['inputmode'] ?? '') === 'numeric') {
					$score += 10;
				}

				if ($score > $cvrScore) {
					$cvrScore = $score;
					$cvrField = $name;
				}
			}

			if ($cvrField === null || $cvrScore < 30) {
				throw new InvalidResponseException('SKAT VAT lookup CVR/SE field could not be discovered safely.');
			}

			if ($searchModeField !== null && $searchModeValue !== null) {
				$fields[$searchModeField] = $searchModeValue;
			}
			$fields[$cvrField] = $registrationNumber;
			$this->appendSearchSubmit($formBody, $fields);

			return [
				'action' => $action,
				'method' => $method,
				'fields' => $fields,
			];
		}

		throw new InvalidResponseException('SKAT VAT lookup CVR/SE search form could not be found.');
	}

	/**
	 * Add the search button of a form to its submission when the button has a name.
	 *
	 * @param  string  $formBody  HTML inside the form.
	 * @param  array<string,string>  $fields  Submission fields; gets the button's name and value.
	 * @return string|null  Name of the button, or null when the form has no search button or the button has no name.
	 */
	private function appendSearchSubmit(string $formBody, array &$fields): ?string {
		if (\preg_match_all('~<button\b([^>]*)>(.*?)</button\s*>~isu', $formBody, $buttons, \PREG_SET_ORDER)) {
			foreach ($buttons as $button) {
				$attrs = self::attributes((string)$button[1]);
				$type = \strtolower($attrs['type'] ?? 'submit');
				$text = $this->visibleText((string)$button[2]);
				if ($type !== 'submit' || !self::containsFolded($text, 'søg')) {
					continue;
				}
				$name = $attrs['name'] ?? '';
				if ($name !== '') {
					$fields[$name] = $attrs['value'] ?? '';
					return $name;
				}
				return null;
			}
		}

		foreach ($this->inputs($formBody) as $input) {
			$type = \strtolower($input['type'] ?? 'text');
			$value = $input['value'] ?? '';
			if ($type !== 'submit' || !self::containsFolded($value, 'søg')) {
				continue;
			}
			$name = $input['name'] ?? '';
			if ($name !== '') {
				$fields[$name] = $value;
				return $name;
			}
			return null;
		}

		return null;
	}



	/**
	 * Return the label texts of a form by the id they belong to.
	 *
	 * @param  string  $html  HTML inside the form.
	 * @return array<string,string>  Visible label text keyed by the for attribute.
	 */
	private function labels(string $html): array {
		$labels = [];
		if (!\preg_match_all('~<label\b([^>]*)>(.*?)</label\s*>~isu', $html, $matches, \PREG_SET_ORDER)) {
			return $labels;
		}

		foreach ($matches as $match) {
			$attrs = self::attributes((string)$match[1]);
			$target = $attrs['for'] ?? '';
			if ($target !== '') {
				$labels[$target] = $this->visibleText((string)$match[2]);
			}
		}

		return $labels;
	}

	/**
	 * Return the attributes of every input element of a form.
	 *
	 * @param  string  $html  HTML inside the form.
	 * @return list<array<string,string>>  Attribute maps, as attributes() returns them.
	 */
	private function inputs(string $html): array {
		if (!\preg_match_all('~<input\b([^>]*)>~isu', $html, $matches, \PREG_SET_ORDER)) {
			return [];
		}

		$inputs = [];
		foreach ($matches as $match) {
			$inputs[] = self::attributes((string)$match[1]);
		}

		return $inputs;
	}

	/**
	 * Parse the attributes of one HTML tag.
	 *
	 * @param  string  $source  Text between the tag name and the closing bracket.
	 * @return array<string,string>  Decoded values keyed by lowercase attribute name; an attribute without a value has its name as value.
	 */
	private static function attributes(string $source): array {
		$attributes = [];
		$pattern = '~([^\s=/>]+)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'=<>`]+)))?~u';
		if (!\preg_match_all($pattern, $source, $matches, \PREG_SET_ORDER)) {
			return $attributes;
		}

		foreach ($matches as $match) {
			$name = \strtolower((string)$match[1]);
			$value = $match[2] ?? $match[3] ?? $match[4] ?? $name;
			$attributes[$name] = \html_entity_decode((string)$value, \ENT_QUOTES | \ENT_HTML5, 'UTF-8');
		}

		return $attributes;
	}

	/**
	 * Resolve a form action against the URL of the search page.
	 *
	 * @param  string  $baseUrl  URL of the search page after redirects.
	 * @param  string  $action  Action attribute of the form, possibly relative or empty.
	 * @return string  Absolute HTTPS URL without a jsessionid.
	 * @throws \CitOmni\DanishPublicSectorData\Exception\InvalidResponseException  When the action uses plain HTTP, or the page URL is not an HTTPS URL with a host.
	 * @throws \UnexpectedValueException  When the action is empty and the page URL is not HTTPS.
	 */
	private function resolveUrl(string $baseUrl, string $action): string {
		$action = \html_entity_decode(\trim($action), \ENT_QUOTES | \ENT_HTML5, 'UTF-8');
		if ($action === '') {
			return self::withoutJsessionId($this->httpsUrl($baseUrl, 'form action'));
		}
		if (\str_starts_with($action, 'https://')) {
			return self::withoutJsessionId($this->httpsUrl($action, 'form action'));
		}
		if (\str_starts_with($action, 'http://')) {
			throw new InvalidResponseException('SKAT VAT lookup form action unexpectedly uses plain HTTP.');
		}

		$parts = \parse_url($baseUrl);
		if (!\is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || !isset($parts['host'])) {
			throw new InvalidResponseException('SKAT VAT lookup effective URL is invalid.');
		}

		$origin = 'https://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
		$path = $parts['path'] ?? '/';

		if (\str_starts_with($action, '//')) {
			return self::withoutJsessionId($this->httpsUrl('https:' . $action, 'form action'));
		}
		if (\str_starts_with($action, '/')) {
			return self::withoutJsessionId($origin . $action);
		}
		if (\str_starts_with($action, '?')) {
			return self::withoutJsessionId($origin . $path . $action);
		}

		$directory = \substr($path, 0, (int)\strrpos($path, '/') + 1);
		return self::withoutJsessionId($this->httpsUrl($origin . $directory . $action, 'form action'));
	}

	/**
	 * Remove a jsessionid path parameter from a URL, since the cookie file holds the session.
	 *
	 * @param  string  $url
	 * @return string  The URL without ;jsessionid=...
	 */
	private static function withoutJsessionId(string $url): string {
		return (string)\preg_replace('~;jsessionid=[^?/#;]*~i', '', $url);
	}

	/**
	 * Turn HTML into its visible text, with whitespace collapsed.
	 *
	 * @param  string  $html
	 * @return string  Text without scripts, styles and tags; entities decoded and no-break spaces made plain.
	 */
	private function visibleText(string $html): string {
		$html = (string)\preg_replace('~<(script|style|noscript)\b[^>]*>.*?</\1\s*>~isu', ' ', $html);
		$text = \html_entity_decode(\strip_tags($html), \ENT_QUOTES | \ENT_HTML5, 'UTF-8');
		$text = \str_replace("\u{00A0}", ' ', $text);
		$text = (string)\preg_replace('/\s+/u', ' ', $text);

		return \trim($text);
	}

	/**
	 * Read the registration state from the text of a result page.
	 *
	 * Behavior:
	 * - false when the page says that no VAT-registered company matches or that no data exists, or
	 *   shows one of SKAT's error codes for that.
	 * - true when the page shows the number and says that the company is VAT-registered.
	 * - null otherwise.
	 *
	 * @param  string  $text  Visible text of the result page.
	 * @param  string  $registrationNumber  The number that was searched for.
	 * @return bool|null
	 */
	private function registrationState(string $text, string $registrationNumber): ?bool {
		if (
			self::containsFolded($text, 'der er ingen momsregistrerede virksomheder på det, du søgte')
			|| self::containsFolded($text, '[tse7110]')
			|| self::containsFolded($text, 'data findes ikke')
			|| self::containsFolded($text, '[7113]')
			|| self::containsFolded($text, '[tse7113]')
		) {
			return false;
		}

		$digits = (string)\preg_replace('/\D+/', '', $text);
		if (!\str_contains($digits, $registrationNumber)) {
			return null;
		}

		if (
			self::containsFolded($text, 'virksomheden er momsregistreret')
			|| self::containsFolded($text, 'listen indeholder momsregistrerede virksomheder')
		) {
			return true;
		}

		return null;
	}

	/**
	 * Read the verification date that a result page shows.
	 *
	 * @param  string  $text  Visible text of the result page.
	 * @return string|null  The date as YYYY-MM-DD, or null when the page shows no valid date.
	 */
	private function verificationDate(string $text): ?string {
		if (!\preg_match('/Verifikationsdato\s*:\s*([0-9]{2})-([0-9]{2})-([0-9]{4})/iu', $text, $match)) {
			return null;
		}

		$date = \DateTimeImmutable::createFromFormat('!d-m-Y', $match[1] . '-' . $match[2] . '-' . $match[3]);
		$errors = \DateTimeImmutable::getLastErrors();
		if ($date === false || (\is_array($errors) && ($errors['warning_count'] !== 0 || $errors['error_count'] !== 0))) {
			return null;
		}

		return $date->format('Y-m-d');
	}

	/**
	 * Tell whether a text contains another, ignoring the case of ASCII letters.
	 *
	 * @param  string  $haystack
	 * @param  string  $needle
	 * @return bool
	 */
	private static function containsFolded(string $haystack, string $needle): bool {
		return \stripos($haystack, $needle) !== false;
	}


	/**
	 * Require an HTTPS URL.
	 *
	 * @param  string  $url  URL to check; surrounding whitespace is ignored.
	 * @param  string  $key  Name of the URL, for the exception message.
	 * @return string  The trimmed URL.
	 * @throws \UnexpectedValueException  When the URL is empty or not HTTPS.
	 */
	private function httpsUrl(string $url, string $key): string {
		$url = \trim($url);
		if ($url === '' || !\str_starts_with($url, 'https://')) {
			throw new \UnexpectedValueException('SKAT VAT web ' . $key . ' must be a non-empty HTTPS URL.');
		}

		return $url;
	}

	/**
	 * Check one timeout from cfg.
	 *
	 * @param  mixed  $value  Timeout from cfg.
	 * @param  string  $key  Cfg key, for the exception message.
	 * @return int|float  Timeout in seconds.
	 * @throws \UnexpectedValueException  When the value is not a finite number of at least 0.
	 */
	private function normalizeTimeout(mixed $value, string $key): int|float {
		if (!\is_int($value) && !\is_float($value)) {
			throw new \UnexpectedValueException('SKAT VAT web ' . $key . ' must be an int or float.');
		}
		if ($value < 0 || (\is_float($value) && !\is_finite($value))) {
			throw new \UnexpectedValueException('SKAT VAT web ' . $key . ' must be finite and >= 0.');
		}

		return $value;
	}
}
