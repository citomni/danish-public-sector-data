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

/*
 * This provider package intentionally has no package-local Composer vendor tree.
 * The normalization test therefore supplies only the minimal CitOmni base-service
 * contract required to load the real production SoilContamination class.
 */
namespace CitOmni\Kernel\Service {
	abstract class BaseService {
	}
}

namespace {
	use CitOmni\DanishPublicSectorData\Service\SoilContamination;
	use CitOmni\DanishPublicSectorData\Support\DkJordWfsClient;

	$root = \dirname(__DIR__);

	require_once $root . '/src/Exception/PublicDataException.php';
	require_once $root . '/src/Exception/InvalidResponseException.php';
	require_once $root . '/src/Service/SoilContamination.php';
	require_once $root . '/src/Support/DkJordWfsClient.php';

	$service = (new \ReflectionClass(SoilContamination::class))->newInstanceWithoutConstructor();
	$checks = 0;

	$invoke = static function (string $method, mixed ...$arguments) use ($service): mixed {
		return (new \ReflectionMethod(SoilContamination::class, $method))->invoke($service, ...$arguments);
	};

	$check = static function (bool $condition, string $message) use (&$checks): void {
		if (!$condition) {
			throw new \RuntimeException('FAIL: ' . $message);
		}
		++$checks;
	};

	$layers = [
		'localized' => [],
		'v1' => [
			[
				'type' => 'Feature',
				'properties' => [
					'Lokalitetsnr' => '851-00047',
					'Lokalitetsejerlavkode' => 2005352,
					'Lokalitetsmatrikler' => '4bz;301a',
					'Jordforureningsattester' => 'https://jord.miljoeportal.dk/report/?elav=2005352&matrnr=4bz;https://jord.miljoeportal.dk/report/?elav=2005352&matrnr=301a',
					'GUID' => 'v1-guid',
				],
			],
		],
		'v2' => [
			[
				'type' => 'Feature',
				'properties' => [
					'Lokalitetsnr' => '851-00047',
					'Lokalitetsejerlavkode' => 2005352,
					'Lokalitetsmatrikler' => '310a;311a;311b;312',
					'Jordforureningsattester' => 'https://jord.miljoeportal.dk/report/?elav=2005352&matrnr=310a;https://jord.miljoeportal.dk/report/?elav=2005352&matrnr=311a;https://jord.miljoeportal.dk/report/?elav=2005352&matrnr=311b;https://jord.miljoeportal.dk/report/?elav=2005352&matrnr=312',
					'GUID' => 'v2-guid',
				],
			],
		],
		'removed_after_mapping' => [],
		'removed_before_mapping' => [],
	];

	$parcel = $invoke('normalizeWfsParcel', 2005352, '311a', $layers);
	$check($parcel['cadastralDistrictIdentifier'] === 2005352, 'Cadastral district is preserved.');
	$check($parcel['landParcelIdentifier'] === '311a', 'Exact land parcel identifier is preserved.');
	$check($parcel['hasDkJordMatch'] === true, 'Matching DKjord feature is reported.');
	$check($parcel['classifications'] === ['v2'], 'Layer membership becomes the parcel classification.');
	$check(\count($parcel['registrations']) === 1, 'One exact DKjord registration is returned.');
	$check(($parcel['registrations'][0]['locationReference'] ?? null) === '851-00047', 'DKjord location reference is preserved.');
	$check(($parcel['registrations'][0]['guid'] ?? null) === 'v2-guid', 'DKjord feature GUID is preserved.');
	$check(
		($parcel['registrations'][0]['certificateUrl'] ?? null) === 'https://jord.miljoeportal.dk/report/?elav=2005352&matrnr=311a',
		'Exact parcel certificate URL is selected from a semicolon-separated source field.'
	);
	$check(
		$parcel['certificateUrls'] === ['https://jord.miljoeportal.dk/report/?elav=2005352&matrnr=311a'],
		'Certificate URLs are normalized and deduplicated.'
	);

	$oldParcel = $invoke('normalizeWfsParcel', 2005352, '311', $layers);
	$check($oldParcel['hasDkJordMatch'] === false, 'Parcel matching does not use substring semantics.');
	$check($oldParcel['classifications'] === [], 'A non-exact matrikelnummer has no classification.');
	$check($oldParcel['registrations'] === [], 'A non-exact matrikelnummer has no registrations.');

	$multi = $layers;
	$multi['v1'][] = [
		'type' => 'Feature',
		'properties' => [
			'Lokalitetsnr' => '851-99999',
			'Lokalitetsejerlavkode' => 2005352,
			'Lokalitetsmatrikler' => '311a',
			'Jordforureningsattester' => 'https://jord.miljoeportal.dk/report/?elav=2005352&matrnr=311a',
			'GUID' => 'v1-second-guid',
		],
	];
	$multiParcel = $invoke('normalizeWfsParcel', 2005352, '311a', $multi);
	$check($multiParcel['classifications'] === ['v1', 'v2'], 'Multiple matching WFS layers retain deterministic classification order.');
	$check(\count($multiParcel['registrations']) === 2, 'Registrations from multiple classification layers are retained.');
	$check(\count($multiParcel['certificateUrls']) === 1, 'Repeated certificate URLs are deduplicated across layers.');

	$check($invoke('splitSemicolonList', ' 4bz ; 301a;; ') === ['4bz', '301a'], 'Semicolon lists are trimmed without fuzzy matching.');
	$check($invoke('splitSemicolonList', null) === [], 'Null semicolon lists normalize to an empty list.');

	$check($invoke('isCurrentParcelStatus', 'GÆLDENDE') === true, 'Uppercase current Matriklen status is accepted.');
	$check($invoke('isCurrentParcelStatus', 'Gældende') === true, 'Mixed-case current Matriklen status is accepted.');
	$check($invoke('isCurrentParcelStatus', 'Foreløbig') === false, 'Non-current Matriklen status is rejected.');

	$soilReflection = new \ReflectionClass(SoilContamination::class);
	$parcelScopeMethod = $soilReflection->getMethod('getParcelsByBfe');
	$check($parcelScopeMethod->isPublic(), 'Pure BFE-to-parcel resolution is exposed without requiring DKjord WFS.');
	$check(
		$soilReflection->getConstant('DKJORD_CERTIFICATE_LAYERS') === ['v1' => true, 'v2' => true],
		'Only V1 and V2 request the DKjord certificate property.'
	);

	$wfsReflection = new \ReflectionClass(DkJordWfsClient::class);
	$buildPropertyNames = $wfsReflection->getMethod('buildPropertyNames');
	$check(
		$buildPropertyNames->invoke(null, false) === 'Lokalitetsnr,Lokalitetsejerlavkode,Lokalitetsmatrikler,GUID',
		'Common WFS projection excludes the V1/V2-only certificate field.'
	);
	$check(
		$buildPropertyNames->invoke(null, true) === 'Lokalitetsnr,Lokalitetsejerlavkode,Lokalitetsmatrikler,GUID,Jordforureningsattester',
		'V1/V2 WFS projection includes Jordforureningsattester.'
	);

	$query = $invoke(
		'buildSfeByBfeQuery',
		4268969,
		'2026-09-30T10:00:00Z',
		null
	);
	$check(\str_contains($query, 'MAT_SamletFastEjendom('), 'BFE lookup targets Matriklen SFE.');
	$check(\str_contains($query, 'BFEnummer: { eq: 4268969 }'), 'BFE lookup is exact.');
	$check(\str_contains($query, 'virkningstid: "2026-09-30T10:00:00Z"'), 'BFE lookup carries an explicit effect time.');

	$query = $invoke(
		'buildParcelsBySfeQuery',
		'11111111-1111-1111-1111-111111111111',
		'2026-09-30T10:00:00Z',
		null
	);
	$check(\str_contains($query, 'MAT_Jordstykke('), 'Parcel lookup targets Matriklen parcels.');
	$check(\str_contains($query, 'first: 1000'), 'Parcel lookup uses the configured page size.');
	$check(\str_contains($query, 'samletFastEjendomLokalId: { eq: "11111111-1111-1111-1111-111111111111" }'), 'Parcel lookup scopes by exact SFE id.');

	\fwrite(
		STDOUT,
		'PASS ' . $checks . ' checks. DKjord WFS parcel matching and Matriklen query contract. PHP '
		. \PHP_VERSION . '.' . \PHP_EOL
	);
}
