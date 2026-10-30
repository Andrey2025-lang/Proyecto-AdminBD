<?php
declare(strict_types=1);

// ZIP y XML Office Open XML. Usa compresión incremental si zlib está disponible.
// Escribe a disco temporal en lugar de mantener el libro entero en memoria.
final class XlsxWriter
{
    private array $entries=[];
    public function __construct(private $file) {}
    private function write(string $data): void
    {
        while ($data!=='') {
            $written=fwrite($this->file,$data);
            if ($written===false || $written===0) throw new RuntimeException('No se pudo generar el archivo.');
            $data=substr($data,$written);
        }
    }
    public function entry(string $name, string|iterable $content): void
    {
        $offset=ftell($this->file); $size=0; $compressed=0; $hash=hash_init('crc32b');
        $deflate=function_exists('deflate_init')?deflate_init(ZLIB_ENCODING_RAW,['level'=>3]):false;
        $method=$deflate?8:0;
        $this->write(pack('VvvvvvVVVvv',0x04034b50,20,8,$method,0,33,0,0,0,strlen($name),0).$name);
        foreach (is_string($content)?[$content]:$content as $part) {
            $size+=strlen($part); hash_update($hash,$part);
            $bytes=$deflate?deflate_add($deflate,$part,ZLIB_NO_FLUSH):$part;
            if ($bytes===false) throw new RuntimeException('No se pudo comprimir el archivo.');
            $compressed+=strlen($bytes); $this->write($bytes);
            if ($size>0xffffffff) throw new RuntimeException('El archivo supera el tamaño permitido.');
        }
        if ($deflate) {
            $bytes=deflate_add($deflate,'',ZLIB_FINISH);
            if ($bytes===false) throw new RuntimeException('No se pudo completar el archivo.');
            $compressed+=strlen($bytes); $this->write($bytes);
        }
        $crc=(int)hexdec(hash_final($hash));
        $this->write(pack('VVVV',0x08074b50,$crc,$compressed,$size));
        $this->entries[]=[$name,$offset,$size,$crc,$compressed,$method];
    }
    public function finish(): void
    {
        $start=ftell($this->file);
        foreach ($this->entries as [$name,$offset,$size,$crc,$compressed,$method]) {
            $this->write(pack('VvvvvvvVVVvvvvvVV',0x02014b50,20,20,8,$method,0,33,$crc,$compressed,$size,strlen($name),0,0,0,0,0,$offset).$name);
        }
        $length=ftell($this->file)-$start; $count=count($this->entries);
        $this->write(pack('VvvvvVVv',0x06054b50,0,0,$count,$count,$length,$start,0));
    }
}
function reportXml(string $value): string
{
    $value=preg_replace('/[^\x09\x0A\x0D\x20-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u','',$value)??'';
    return htmlspecialchars($value,ENT_XML1|ENT_QUOTES,'UTF-8');
}
function excelColumn(int $index): string
{
    $name='';
    do { $name=chr(65+$index%26).$name; $index=intdiv($index,26)-1; } while($index>=0);
    return $name;
}
function excelCell(string $address, $value, string $type='text', int $style=0): string
{
    if ($type==='date' && $value!==null && $value!=='') {
        $date=new DateTimeImmutable((string)$value,new DateTimeZone('UTC'));
        $serial=($date->getTimestamp()+2209161600)/86400;
        return '<c r="'.$address.'" s="'.($style?:5).'"><v>'.sprintf('%.10F',$serial).'</v></c>';
    }
    if (in_array($type,['money','int'],true)) {
        if (!preg_match('/^-?\d+(?:\.\d+)?$/D',(string)$value)) throw new RuntimeException('El reporte contiene un número inválido.');
        return '<c r="'.$address.'" s="'.($style?:($type==='money'?3:4)).'"><v>'.$value.'</v></c>';
    }
    // inlineStr impide que textos iniciados con =, +, - o @ sean fórmulas.
    return '<c r="'.$address.'" t="inlineStr" s="'.$style.'"><is><t xml:space="preserve">'.reportXml((string)($value??'')).'</t></is></c>';
}
function xlsxSheet(PDOStatement|iterable $stmt, array $plan, array $summary, string $title, string $caption, string $generated): Generator
{
    $last=excelColumn(count($plan['columns'])-1);
    yield '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheetPr><pageSetUpPr fitToPage="1"/></sheetPr><sheetViews><sheetView workbookViewId="0" showGridLines="0"><pane xSplit="1" ySplit="5" topLeftCell="B6" activePane="bottomRight" state="frozen"/></sheetView></sheetViews><sheetFormatPr defaultRowHeight="24"/><cols>';
    foreach($plan['columns'] as $i=>[$key,$label,$type]) yield '<col min="'.($i+1).'" max="'.($i+1).'" width="'.($type==='text'?($key==='nombre'||$key==='persona'?62:($key==='motivo'?48:28)):($type==='date'?24:20)).'" customWidth="1"/>';
    yield '</cols><sheetData><row r="1" ht="38" customHeight="1">'.excelCell('A1','REPUESTO  /  '.$title,'text',1).'</row><row r="2" ht="22" customHeight="1">'.excelCell('A2','Fecha de emisión: '.dateLabel($generated),'text',12).'</row><row r="3" ht="34" customHeight="1">'.excelCell('A3',$caption,'text',12).'</row><row r="4" ht="28" customHeight="1">'.excelCell('A4',($summary['record_label']??'Resultados').': '.number_format((int)$summary['total']).(isset($summary['importe'])?'    ·    Monto total: '.number_format((float)$summary['importe'],2,'.',','):''),'text',12).'</row><row r="5" ht="30" customHeight="1">';
    foreach($plan['columns'] as $i=>[$key,$label]) yield excelCell(excelColumn($i).'5',$label,'text',2);
    yield '</row>'; $number=6;
    foreach(reportRecords($stmt) as $record) {
        yield '<row r="'.$number.'">';
        foreach($plan['columns'] as $i=>[$key,$label,$type]) {
            $style=$number%2===1?match($type){'money'=>8,'int'=>9,'date'=>10,default=>7}:0;
            yield excelCell(excelColumn($i).$number,reportValue($key,$record[$key]??''),$type,$style);
        }
        yield '</row>'; $number++;
    }
    if (isset($summary['importe'])) {
        $cell=(int)$summary['total']>0?'<c r="'.$last.$number.'" s="6"><f>SUBTOTAL(109,'.$last.'6:'.$last.($number-1).')</f><v>'.$summary['importe'].'</v></c>':excelCell($last.$number,$summary['importe'],'money',6);
        yield '<row r="'.$number.'" ht="29" customHeight="1">'.excelCell('A'.$number,'Total visible','text',11).$cell.'</row>';
    }
    yield '</sheetData><mergeCells count="4"><mergeCell ref="A1:'.$last.'1"/><mergeCell ref="A2:'.$last.'2"/><mergeCell ref="A3:'.$last.'3"/><mergeCell ref="A4:'.$last.'4"/></mergeCells><printOptions horizontalCentered="1"/><pageMargins left="0.25" right="0.25" top="0.5" bottom="0.5" header="0.2" footer="0.2"/><pageSetup orientation="landscape" paperSize="9" fitToWidth="1" fitToHeight="0"/><headerFooter><oddFooter>&amp;LRepuesto · Documento informativo&amp;RPágina &amp;P de &amp;N</oddFooter></headerFooter>'.((int)$summary['total']>0?'<tableParts count="1"><tablePart r:id="rId1"/></tableParts>':'').'</worksheet>';
}
function xlsxOverview(array $summary, array $blocks, string $title, string $caption, string $generated): Generator
{
    yield '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetViews><sheetView workbookViewId="0" showGridLines="0"/></sheetViews><sheetFormatPr defaultRowHeight="24"/><cols><col min="1" max="1" width="42" customWidth="1"/><col min="2" max="2" width="20" customWidth="1"/><col min="3" max="5" width="24" customWidth="1"/></cols><sheetData>';
    yield '<row r="1" ht="42" customHeight="1">'.excelCell('A1','REPUESTO  /  Resumen','text',1).'</row><row r="2">'.excelCell('A2',$title.' · Emitido: '.dateLabel($generated),'text',12).'</row><row r="3" ht="38" customHeight="1">'.excelCell('A3',$caption,'text',12).'</row><row r="5">'.excelCell('A5',$summary['record_label']??'Resultados','text',12).excelCell('C5',isset($summary['importe'])?($summary['amount_label']??'Monto total'):'Contenido','text',12).'</row><row r="6" ht="36" customHeight="1">'.excelCell('A6',$summary['total'],'int',13).excelCell('C6',$summary['importe']??'Consulte la hoja Datos',isset($summary['importe'])?'money':'text',isset($summary['importe'])?14:12).'</row>';
    $number=9; $merges=['A1:E1','A2:E2','A3:E3','A6:B6','C6:E6'];
    foreach($blocks as $block) {
        yield '<row r="'.$number.'" ht="32" customHeight="1">'.excelCell('A'.$number,$block['title'],'text',1).'</row>';
        $merges[]='A'.$number.':E'.$number; $number++;
        yield '<row r="'.$number.'" ht="29" customHeight="1">';
        foreach($block['columns'] as $i=>[$key,$label]) yield excelCell(excelColumn($i).$number,$label,'text',2);
        yield '</row>'; $number++;
        foreach($block['rows'] as $record) {
            yield '<row r="'.$number.'">';
            foreach($block['columns'] as $i=>[$key,$label,$type]) yield excelCell(excelColumn($i).$number,reportValue($key,$record[$key]??''),$type,$number%2?match($type){'money'=>8,'int'=>9,default=>7}:0);
            yield '</row>'; $number++;
        }
        $number+=2;
    }
    yield '<row r="'.$number.'" ht="34" customHeight="1">'.excelCell('A'.$number,'Documento informativo · Importes en la moneda del negocio.','text',12).'</row></sheetData><mergeCells count="'.count($merges).'">';
    foreach($merges as $merge) yield '<mergeCell ref="'.$merge.'"/>';
    yield '</mergeCells><pageMargins left="0.3" right="0.3" top="0.5" bottom="0.5" header="0.2" footer="0.2"/><pageSetup orientation="landscape" paperSize="9" fitToWidth="1" fitToHeight="0"/></worksheet>';
}
function xlsxStyles(): string
{
    $xml='<?xml version="1.0"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><numFmts count="2"><numFmt numFmtId="164" formatCode="#,##0.00"/><numFmt numFmtId="165" formatCode="dd/mm/yyyy hh:mm"/></numFmts><fonts count="4"><font><sz val="11"/><color rgb="FF304D3D"/><name val="Calibri"/></font><font><b/><sz val="17"/><color rgb="FF176F57"/><name val="Calibri"/></font><font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font><font><sz val="10"/><color rgb="FF75877B"/><name val="Calibri"/></font></fonts><fills count="4"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF176F57"/><bgColor indexed="64"/></patternFill></fill><fill><patternFill patternType="solid"><fgColor rgb="FFEDF5EF"/><bgColor indexed="64"/></patternFill></fill></fills><borders count="2"><border/><border><bottom style="hair"><color rgb="FFDFE9E2"/></bottom></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="15">';
    foreach([[0,0,1,0,'left'],[1,0,0,0,'left'],[2,2,0,0,'left'],[0,0,1,164,'right'],[0,0,1,3,'right'],[0,0,1,165,'center'],[2,2,0,164,'right'],[0,3,1,0,'left'],[0,3,1,164,'right'],[0,3,1,3,'right'],[0,3,1,165,'center'],[2,2,0,0,'left'],[3,0,0,0,'left'],[1,3,0,3,'left'],[1,3,0,164,'left']] as [$font,$fill,$border,$format,$align]) {
        $xml.='<xf numFmtId="'.$format.'" fontId="'.$font.'" fillId="'.$fill.'" borderId="'.$border.'" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyNumberFormat="1" applyAlignment="1"><alignment horizontal="'.$align.'" vertical="center" wrapText="1"/></xf>';
    }
    return $xml.'</cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles><dxfs count="2"><dxf><font><b/><color rgb="FFFFFFFF"/></font><fill><patternFill patternType="solid"><fgColor rgb="FF176F57"/><bgColor indexed="64"/></patternFill></fill></dxf><dxf><fill><patternFill patternType="solid"><fgColor rgb="FFEDF5EF"/><bgColor indexed="64"/></patternFill></fill></dxf></dxfs><tableStyles count="1" defaultTableStyle="RepuestoGreen" defaultPivotStyle="PivotStyleLight16"><tableStyle name="RepuestoGreen" pivot="0" table="1" count="2"><tableStyleElement type="headerRow" dxfId="0"/><tableStyleElement type="firstRowStripe" size="1" dxfId="1"/></tableStyle></tableStyles></styleSheet>';
}
function writeWorkbook($file, iterable $sheet, array $plan, array $summary, iterable $overview): void
{
    $zip=new XlsxWriter($file);
    $table=(int)$summary['total']>0;
    $zip->entry('[Content_Types].xml','<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/worksheets/sheet2.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'.($table?'<Override PartName="/xl/tables/table1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.table+xml"/>':'').'</Types>');
    $zip->entry('_rels/.rels','<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
    $zip->entry('xl/workbook.xml','<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><bookViews><workbookView activeTab="0"/></bookViews><sheets><sheet name="Resumen" sheetId="2" r:id="rId3"/><sheet name="Datos" sheetId="1" r:id="rId1"/></sheets><calcPr calcId="191029" fullCalcOnLoad="1"/></workbook>');
    $zip->entry('xl/_rels/workbook.xml.rels','<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/><Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet2.xml"/></Relationships>');
    $zip->entry('xl/styles.xml',xlsxStyles());
    $zip->entry('xl/worksheets/sheet1.xml',$sheet);
    $zip->entry('xl/worksheets/sheet2.xml',$overview);
    if ($table) {
        $last=excelColumn(count($plan['columns'])-1); $dataEnd=5+(int)$summary['total']; $totals=isset($summary['importe']);
        $xml='<?xml version="1.0" encoding="UTF-8"?><table xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" id="1" name="DatosReporte" displayName="DatosReporte" ref="A5:'.$last.($dataEnd+($totals?1:0)).'" totalsRowCount="'.($totals?1:0).'" totalsRowShown="'.($totals?1:0).'"><autoFilter ref="A5:'.$last.$dataEnd.'"/><tableColumns count="'.count($plan['columns']).'">';
        foreach($plan['columns'] as $i=>[$key,$label]) $xml.='<tableColumn id="'.($i+1).'" name="'.reportXml($label).'"'.($totals&&$i===0?' totalsRowLabel="Total visible"':($totals&&$i===count($plan['columns'])-1?' totalsRowFunction="sum"':'')).'/>';
        $zip->entry('xl/tables/table1.xml',$xml.'</tableColumns><tableStyleInfo name="RepuestoGreen" showFirstColumn="0" showLastColumn="0" showRowStripes="1" showColumnStripes="0"/></table>');
        $zip->entry('xl/worksheets/_rels/sheet1.xml.rels','<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/table" Target="../tables/table1.xml"/></Relationships>');
    }
    $zip->finish();
}
