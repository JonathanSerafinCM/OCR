<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use thiagoalessio\TesseractOCR\TesseractOCR;
use App\Models\Menu;
use Illuminate\Support\Facades\Storage;

class MenuOCRController extends Controller
{
    public function processMenu(Request $request)
    {
        $request->validate([
            'menu_file' => 'required|file|mimes:jpg,png,pdf'
        ]);

        $file = $request->file('menu_file');
        $path = Storage::disk('local')->putFile('temp', $file);
        $fullPath = Storage::disk('local')->path($path);
        
        // Si es PDF, convertir a imagen
        if ($file->getClientOriginalExtension() === 'pdf') {
            $outputPath = Storage::disk('local')->path('temp/output');
            exec("pdftoppm -png {$fullPath} {$outputPath}");
            $fullPath = $outputPath . '-1.png';  // pdftoppm adds -1 for first page
        }

        try {
            // Procesar con Tesseract
            $text = (new TesseractOCR($fullPath))
                ->lang('spa')
                ->run();

            // Procesar el texto extraído
            $menuItems = $this->parseMenuText($text);

            // Guardar en la base de datos
            foreach ($menuItems as $item) {
                Menu::create($item);
            }

            // Limpiar archivos temporales
            Storage::disk('local')->delete($path);
            if (isset($outputPath)) {
                Storage::disk('local')->delete('temp/output-1.png');
            }

            return response()->json(['message' => 'Menu processed successfully', 'items' => count($menuItems)]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    private function parseMenuText($text)
    {
        $items = [];
        $lines = explode("\n", $text);
        $currentCategory = '';
        
        foreach ($lines as $line) {
            // Detectar categorías (generalmente en mayúsculas)
            if (preg_match('/^[A-ZÁÉÍÓÚÑ\s]{3,}$/', trim($line))) {
                $currentCategory = trim($line);
                continue;
            }

            // Detectar platos y precios
            if (preg_match('/^(.+?)\s*(\d{1,3}(?:,\d{2})?(?:\.\d{2})?)\s*€?$/', $line, $matches)) {
                $items[] = [
                    'category' => $currentCategory,
                    'dish_name' => trim($matches[1]),
                    'price' => (float) str_replace(',', '.', $matches[2]),
                    'description' => '',
                    'special_notes' => ''
                ];
            }
        }

        return $items;
    }
}
