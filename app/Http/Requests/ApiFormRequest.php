<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

abstract class ApiFormRequest extends FormRequest
{
    /**
     * Get the custom validation messages for the request.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'required' => ':attribute wajib diisi.',
            'email' => 'Format :attribute tidak valid.',
            'string' => ':attribute harus berupa teks.',
            'integer' => ':attribute harus berupa angka.',
            'numeric' => ':attribute harus berupa angka.',
            'boolean' => ':attribute harus bernilai benar atau salah.',
            'url' => 'Format :attribute tidak valid.',
            'alpha_dash' => ':attribute hanya boleh berisi huruf, angka, tanda hubung, dan garis bawah.',
            'alpha' => ':attribute hanya boleh berisi huruf.',
            'date' => 'Format :attribute tidak valid.',
            'unique' => ':attribute sudah digunakan.',
            'exists' => ':attribute yang dipilih tidak ditemukan.',
            'in' => 'Nilai :attribute tidak valid.',
            'max.string' => ':attribute tidak boleh lebih dari :max karakter.',
            'max.numeric' => ':attribute tidak boleh lebih dari :max.',
            'max' => ':attribute tidak boleh lebih dari :max.',
            'min.numeric' => ':attribute minimal :min.',
            'min' => ':attribute minimal :min.',
            'size' => ':attribute harus terdiri dari :size karakter.',
        ];
    }

    /**
     * Get custom attributes for validator errors.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'application_id' => 'Aplikasi',
            'application_customer_id' => 'Pelanggan aplikasi',
            'application_url' => 'URL aplikasi',
            'currency' => 'Mata uang',
            'code' => 'Kode aplikasi',
            'contact_email' => 'Email kontak',
            'description' => 'Deskripsi',
            'direction' => 'Arah pengurutan',
            'duration_months' => 'Durasi paket',
            'email' => 'Email',
            'external_customer_id' => 'ID pelanggan pada aplikasi',
            'due_at' => 'Tanggal jatuh tempo',
            'is_active' => 'Status aktif',
            'is_test' => 'Data uji',
            'idempotency_key' => 'Kunci idempotensi',
            'invoice_id' => 'Invoice',
            'invoice_number' => 'Nomor invoice',
            'name' => 'Nama',
            'owner_name' => 'Nama pemilik',
            'page' => 'Nomor halaman',
            'password' => 'Kata sandi',
            'per_page' => 'Jumlah data per halaman',
            'price' => 'Harga',
            'provider' => 'Penyedia pembayaran',
            'phone_number' => 'Nomor telepon',
            'search' => 'Kata pencarian',
            'sort' => 'Kolom pengurutan',
            'status' => 'Status',
            'subscription_id' => 'Langganan',
            'subscription_plan_id' => 'Paket langganan',
            'username' => 'Username',
        ];
    }
}
