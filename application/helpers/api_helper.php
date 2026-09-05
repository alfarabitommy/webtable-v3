<?php
defined('BASEPATH') OR exit('No direct script access allowed');

// ===================================================================
//  API JSON HELPERS — M9/P7 (plan/76)
//
//  Choke-point tunggal untuk SEMUA respons JSON AJAX/fetch: satu format
//  envelope, satu content-type, satu encoder, HTTP status eksplisit,
//  lalu exit. Dipakai dari controller mana pun (termasuk yang extends
//  CI_Controller: Auth/Admin/Admin_auth — karena itu helper, bukan method
//  base MY_Controller) dan dari MY_Exceptions (error path 404/500 AJAX).
//
//  Kontrak backward-compat (plan/76 §6): argumen $legacy memetakan key
//  lama yang dibaca frontend agar tetap hadir di root — view/JS TIDAK
//  perlu diubah. Relatif terhadap envelope:
//    sukses : {success:true, message, data} + key legacy
//    error  : {success:false, message, errors, data:null[, code]} + legacy
//  Envelope bersifat additive; key legacy di-retire di round terpisah
//  (setelah konsumen dimigrasikan), BUKAN di round ini.
//
//  Semua fungsi dibungkus function_exists() agar aman di-include ganda
//  (CI Loader memakai include; MY_Exceptions/ratelimit_helper memakai
//  require_once — guards mencegah redeclare fatal).
// ===================================================================

if ( ! function_exists('_api_send'))
{
	/**
	 * Kirim body JSON + status + content-type, lalu akhiri request.
	 *
	 * @param array $body Body JSON final (urutan key = urutan insert)
	 * @param int   $http HTTP status (100-599; di luar rentang -> 500)
	 * @return void
	 */
	function _api_send(array $body, $http)
	{
		$http = (int) $http;
		if ($http < 100 || $http > 599)
		{
			$http = 500;
		}

		if ( ! headers_sent())
		{
			set_status_header($http);
			header('Content-Type: application/json');
		}

		echo json_encode(
			$body,
			JSON_UNESCAPED_UNICODE
				| JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
		);
		exit;
	}
}

if ( ! function_exists('api_success'))
{
	/**
	 * Respons JSON sukses terstandar (HTTP 200/201).
	 *
	 * @param mixed  $data    Payload (object/array/null) pada key `data`
	 * @param string $message Pesan human-readable (opsional, bahasa Indonesia)
	 * @param int    $http    HTTP status (default 200)
	 * @param array  $legacy  Key legacy root yang wajib dipertahankan
	 * @return void
	 */
	function api_success($data = null, $message = '', $http = 200, array $legacy = [])
	{
		$body = [
			'success' => true,
			'message' => (string) $message,
			'data'    => $data,
		];

		foreach ($legacy as $key => $value)
		{
			$body[$key] = $value;
		}

		_api_send($body, $http);
	}
}

if ( ! function_exists('api_error'))
{
	/**
	 * Respons JSON error terstandar (400/401/403/404/409/422/429/500).
	 *
	 * @param string      $message Pesan human-readable (Indonesia)
	 * @param int         $http    HTTP status (default 400)
	 * @param array       $errors  Daftar error field-level/validasi
	 * @param string|null $code    Kode mesin opsional utk branching client
	 * @param array       $legacy  Key legacy root yang wajib dipertahankan
	 * @return void
	 */
	function api_error($message, $http = 400, array $errors = [], $code = null, array $legacy = [])
	{
		$body = [
			'success' => false,
			'message' => (string) $message,
			'errors'  => array_values($errors),
			'data'    => null,
		];

		if ($code !== null)
		{
			$body['code'] = (string) $code;
		}

		foreach ($legacy as $key => $value)
		{
			$body[$key] = $value;
		}

		_api_send($body, $http);
	}
}
