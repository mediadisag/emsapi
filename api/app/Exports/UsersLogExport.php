<?php

namespace App\Exports;

use App\Models\UsersLog;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;

class UsersLogExport implements FromCollection, WithHeadings
{
    protected $logs;

    public function __construct($logs)
    {
        $this->logs = $logs;
    }

    public function collection()
    {
        return $this->logs;
    }

    public function headings(): array
    {
        return [
            'Check-in Date',
            'Time In',
            'Time Out',
            'Device ID',
            'Device Department',
            'Device Branch',
            'Fingerprint ID',
            'Username',
            'Email',
            'Full Name',
            'Phone',
            'Department',
            'Branch',
             ];
    }
}