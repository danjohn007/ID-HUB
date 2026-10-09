<?php

namespace App\Services;

use Barryvdh\DomPDF\Facade\Pdf;
use Closure;
use Illuminate\Support\Str;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Exportación de reportes a Excel (XLSX), CSV o PDF.
 *
 *   return app(ReportExporter::class)->download('clientes', 'xlsx', [
 *       'Código' => 'code', 'Plan' => 'plan.name', 'Saldo' => fn ($c) => (float) $c->balance,
 *   ], $rows, 'Clientes');
 *
 * Columnas: etiqueta => clave (notación punto) o closure. $rows: iterable de modelos o arreglos.
 */
class ReportExporter
{
    public function download(string $name, string $format, array $columns, iterable $rows, ?string $title = null, array $summary = [])
    {
        $filename = Str::slug($name).'-'.now()->format('Ymd-His');
        $data = $this->table($columns, $rows);

        return match ($format) {
            'csv' => $this->csv($filename, $data),
            'pdf' => $this->pdf($filename, $data, $title ?? $name, $summary),
            default => $this->xlsx($filename, $data, $title ?? $name),
        };
    }

    public function table(array $columns, iterable $rows): array
    {
        $out = ['headers' => array_keys($columns), 'rows' => []];
        foreach ($rows as $row) {
            $line = [];
            foreach ($columns as $col) {
                $value = $col instanceof Closure ? $col($row) : data_get($row, $col);
                if ($value instanceof \DateTimeInterface) {
                    $value = $value->format('d/m/Y H:i');
                } elseif (is_bool($value)) {
                    $value = $value ? 'Sí' : 'No';
                } elseif (is_array($value)) {
                    $value = implode(', ', $value);
                }
                $line[] = $value;
            }
            $out['rows'][] = $line;
        }

        return $out;
    }

    protected function csv(string $filename, array $data): StreamedResponse
    {
        return response()->streamDownload(function () use ($data) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // BOM para Excel
            fputcsv($out, $data['headers']);
            foreach ($data['rows'] as $row) {
                fputcsv($out, $row);
            }
            fclose($out);
        }, $filename.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    protected function xlsx(string $filename, array $data, string $title): StreamedResponse
    {
        return response()->streamDownload(function () use ($data, $title) {
            $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
            $writer = new XlsxWriter;
            $writer->openToFile($tmp);
            $writer->getCurrentSheet()->setName(Str::limit(preg_replace('/[^\p{L}\p{N} ]/u', '', $title), 28, ''));
            $header = (new Style)->withFontBold(true)->withFontColor('FFFFFF')->withBackgroundColor('0B1B3A');
            $writer->addRow(Row::fromValuesWithStyle($data['headers'], $header));
            foreach ($data['rows'] as $row) {
                $writer->addRow(Row::fromValues(array_map(fn ($v) => $v ?? '', $row)));
            }
            $writer->close();
            readfile($tmp);
            @unlink($tmp);
        }, $filename.'.xlsx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    protected function pdf(string $filename, array $data, string $title, array $summary): StreamedResponse
    {
        $pdf = Pdf::loadView('pdf.report', [
            'title' => $title, 'data' => $data, 'summary' => $summary, 'tenant' => tenant(),
        ])->setPaper('letter', count($data['headers']) > 7 ? 'landscape' : 'portrait');

        return response()->streamDownload(fn () => print($pdf->output()), $filename.'.pdf', ['Content-Type' => 'application/pdf']);
    }
}
