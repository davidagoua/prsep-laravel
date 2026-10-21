<?php

namespace Database\Seeders\Support;

use Illuminate\Http\UploadedFile;
use ZipArchive;

/**
 * Document Word minimal (titre et paragraphes), pour les données de démonstration et les tests.
 */
class DemoDocx
{
    /** @param  list<string>  $paragraphs */
    public static function make(string $title, array $paragraphs = [], string $name = 'rapport.docx'): UploadedFile
    {
        $esc = fn (string $t) => htmlspecialchars($t, ENT_XML1);
        $body = '<w:p><w:pPr><w:pStyle w:val="Title"/></w:pPr><w:r><w:rPr><w:b/><w:sz w:val="32"/></w:rPr><w:t>'.$esc($title).'</w:t></w:r></w:p>';
        foreach ($paragraphs as $p) {
            $body .= '<w:p><w:r><w:t xml:space="preserve">'.$esc($p).'</w:t></w:r></w:p>';
        }

        $path = tempnam(sys_get_temp_dir(), 'docx');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>');
        $zip->addFromString('word/document.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>'.$body.'<w:sectPr/></w:body></w:document>');
        $zip->close();

        return new UploadedFile($path, $name, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', null, true);
    }
}
