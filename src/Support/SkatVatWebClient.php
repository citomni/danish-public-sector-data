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
 * SkatVatWebClient: Query the anonymous SKAT VAT-number lookup web flow.
 *
 * Behavior:
 * - Starts a fresh anonymous web session and discovers the current search form.
 * - Submits one exact eight-digit CVR/SE number with the form's hidden flow state.
 * - Recognizes SKAT's explicit positive/negative result text conservatively.
 *
 * Notes:
 * - This is a temporary web-integration fallback, not a documented SKAT API contract.
 * - No CAPTCHA, login, authorization control, or other access restriction is bypassed.
 * - Session cookies and jsessionid-bearing URLs remain internal to the request flow.
 * - The HTML parser intentionally discovers current form field names instead of hard-coding Spring Web Flow internals.
 *
 * @internal
 */
final class SkatVatWebClient {

	private App $app;
	private string $searchUrl;
	private int|float $timeout;
	private int|float $connectTimeout;

	/**
	 * Create the internal SKAT VAT web client.
	 *
	 * @param App $app Application container.
	 */
	public function __construct(App $app) {
		$this->app = $app;

		$cfg = $this->app->cfg->danish_public_sector_data->skat_vat_web;
		$this->searchUrl = $this->httpsUrl((string)($cfg->search_url ?? ''), 'search_url');
		$this->timeout = $this->normalizeTimeout($cfg->timeout ?? 15, 'timeout');
		$this->connectTimeout = $this->normalizeTimeout($cfg->connect_timeout ?? 5, 'connect_timeout');
	}

	/**
	 * Query SKAT's public VAT-number lookup for one exact CVR/SE number.
	 *
	 * @param string $registrationNumber Validated eight-digit CVR/SE number.
	 * @return array{registrationNumber:string,registered:?bool,verifiedOn:?string} Parsed current status.
	 * @throws \CitOmni\DanishPublicSectorData\Exception\RateLimitException When SKAT rate-limits the request.
	 * @throws \CitOmni\DanishPublicSectorData\Exception\RemoteServiceException When transport or HTTP execution fails.
	 * @throws \CitOmni\DanishPublicSectorData\Exception\InvalidResponseException When the search flow cannot be interpreted safely.
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
	 * Execute one SKAT request and translate transport/HTTP failures.
	 *
	 * @param array<string,mixed> $request CitOmni Curl request.
	 * @return array<string,mixed> CitOmni Curl response.
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
	 * Discover the CVR/SE search form and build the exact submission fields.
	 *
	 * @param string $html Initial SKAT search page.
	 * @param string $effectiveUrl Redirect-resolved search URL containing the active flow state.
	 * @param string $registrationNumber Exact eight-digit CVR/SE number.
	 * @return array{action:string,method:string,fields:array<string,string>} Parsed form submission.
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
	 * Add the search submit control to a form submission when it has a name.
	 *
	 * @param string $formBody Search form HTML.
	 * @param array<string,string> $fields Mutable form field map.
	 * @return string|null Submit field name when one is required.
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
	 * Extract labels keyed by their target id.
	 *
	 * @param string $html Form HTML.
	 * @return array<string,string> Visible label text by target id.
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
	 * Extract input attributes from one form body.
	 *
	 * @param string $html Form HTML.
	 * @return list<array<string,string>> Input attribute maps.
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
	 * Parse a compact HTML attribute fragment without requiring ext-dom.
	 *
	 * @param string $source Text between the tag name and closing bracket.
	 * @return array<string,string> Lowercase attribute names with decoded values.
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
	 * Resolve a form action against the redirect-resolved search URL.
	 *
	 * @param string $baseUrl Effective search URL.
	 * @param string $action Raw form action, possibly relative.
	 * @return string Absolute HTTPS action URL.
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
	 * Remove URL-rewritten anonymous session IDs once the cookie jar owns the session.
	 *
	 * @param string $url HTTPS URL.
	 * @return string URL without a `;jsessionid=...` path parameter.
	 */
	private static function withoutJsessionId(string $url): string {
		return (string)\preg_replace('~;jsessionid=[^?/#;]*~i', '', $url);
	}

	/**
	 * Convert HTML to compact visible text suitable for deterministic matching.
	 *
	 * @param string $html HTML response.
	 * @return string Normalized visible text.
	 */
	private function visibleText(string $html): string {
		$html = (string)\preg_replace('~<(script|style|noscript)\b[^>]*>.*?</\1\s*>~isu', ' ', $html);
		$text = \html_entity_decode(\strip_tags($html), \ENT_QUOTES | \ENT_HTML5, 'UTF-8');
		$text = \str_replace("\u{00A0}", ' ', $text);
		$text = (string)\preg_replace('/\s+/u', ' ', $text);

		return \trim($text);
	}

	/**
	 * Interpret only explicit SKAT result phrases; ambiguity remains null.
	 *
	 * @param string $text Normalized result-page text.
	 * @param string $registrationNumber Queried number.
	 * @return bool|null Registration state or null when the page is not decisive.
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
	 * Read and normalize SKAT's displayed verification date when present.
	 *
	 * @param string $text Normalized result-page text.
	 * @return string|null ISO date or null when no date is displayed.
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
	 * Compare user-facing Danish text case-insensitively without requiring mbstring.
	 *
	 * @param string $haystack Text to search.
	 * @param string $needle Text to find.
	 * @return bool True when present.
	 */
	private static function containsFolded(string $haystack, string $needle): bool {
		return \stripos($haystack, $needle) !== false;
	}


	/**
	 * Require one configured HTTPS URL.
	 *
	 * @param string $url Raw configured URL.
	 * @param string $key Configuration key for diagnostics.
	 * @return string Validated HTTPS URL.
	 */
	private function httpsUrl(string $url, string $key): string {
		$url = \trim($url);
		if ($url === '' || !\str_starts_with($url, 'https://')) {
			throw new \UnexpectedValueException('SKAT VAT web ' . $key . ' must be a non-empty HTTPS URL.');
		}

		return $url;
	}

	/**
	 * Normalize a timeout without silently accepting invalid configuration.
	 *
	 * @param mixed $value Raw configured timeout.
	 * @param string $key Configuration key for diagnostics.
	 * @return int|float Timeout in seconds.
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
