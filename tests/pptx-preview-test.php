<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/legacy/includes/pptx-preview.php';

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function fixture(array $parts): string
{
    $path = tempnam(sys_get_temp_dir(), 'ims_pptx_test_');
    if ($path === false) {
        throw new RuntimeException('Could not create test fixture.');
    }
    $zip = new ZipArchive();
    check($zip->open($path, ZipArchive::OVERWRITE) === true, 'Could not open fixture ZIP.');
    foreach ($parts as $name => $contents) {
        $zip->addFromString($name, $contents);
    }
    $zip->close();
    return $path;
}

function expectFailure(array $parts, string $message): void
{
    $path = fixture($parts);
    try {
        $failed = false;
        try {
            (new DocPptxPreview())->render($path);
        } catch (RuntimeException $exception) {
            $failed = true;
        }
        check($failed, $message);
    } finally {
        unlink($path);
    }
}

$p = 'http://schemas.openxmlformats.org/presentationml/2006/main';
$a = 'http://schemas.openxmlformats.org/drawingml/2006/main';
$r = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
$rel = 'http://schemas.openxmlformats.org/package/2006/relationships';
$parts = [
    'ppt/presentation.xml' => '<p:presentation xmlns:p="' . $p . '" xmlns:r="' . $r . '"><p:sldIdLst>'
        . '<p:sldId id="256" r:id="second"/><p:sldId id="257" r:id="first"/></p:sldIdLst></p:presentation>',
    'ppt/_rels/presentation.xml.rels' => '<Relationships xmlns="' . $rel . '">'
        . '<Relationship Id="first" Type="' . $r . '/slide" Target="slides/slide1.xml"/>'
        . '<Relationship Id="second" Type="' . $r . '/slide" Target="/ppt/slides/slide2.xml"/></Relationships>',
    'ppt/slides/slide1.xml' => '<p:sld xmlns:p="' . $p . '" xmlns:a="' . $a . '"><p:cSld><p:spTree>'
        . '<p:sp><p:txBody><a:p><a:r><a:t>Last slide</a:t></a:r></a:p></p:txBody></p:sp>'
        . '</p:spTree></p:cSld></p:sld>',
    'ppt/slides/slide2.xml' => '<p:sld xmlns:p="' . $p . '" xmlns:a="' . $a . '" xmlns:r="' . $r . '"><p:cSld><p:spTree>'
        . '<p:grpSp><p:sp><p:txBody><a:p><a:pPr><a:buChar char="*"/></a:pPr><a:r><a:t>First &lt;script&gt;alert(1)&lt;/script&gt;</a:t></a:r>'
        . '<a:br/><a:r><a:t>Second line</a:t></a:r></a:p></p:txBody></p:sp></p:grpSp>'
        . '<p:graphicFrame><a:graphic><a:graphicData><a:tbl><a:tr><a:tc><a:txBody><a:p><a:r><a:t>Table cell</a:t></a:r></a:p></a:txBody></a:tc></a:tr></a:tbl></a:graphicData></a:graphic></p:graphicFrame>'
        . '<p:pic><p:nvPicPr><p:cNvPr descr="Image &quot;description&quot;"/></p:nvPicPr><p:blipFill><a:blip r:embed="image"/></p:blipFill></p:pic>'
        . '<p:pic><p:blipFill><a:blip r:link="external"/></p:blipFill></p:pic>'
        . '<p:pic><p:blipFill><a:blip r:embed="svg"/></p:blipFill></p:pic>'
        . '</p:spTree></p:cSld></p:sld>',
    'ppt/slides/_rels/slide2.xml.rels' => '<Relationships xmlns="' . $rel . '">'
        . '<Relationship Id="image" Type="' . $r . '/image" Target="../media/image.png"/>'
        . '<Relationship Id="external" Type="' . $r . '/image" TargetMode="External" Target="https://example.com/private.png"/>'
        . '<Relationship Id="svg" Type="' . $r . '/image" Target="../media/image.svg"/></Relationships>',
    'ppt/media/image.png' => base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII='),
    'ppt/media/image.svg' => '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>',
];

$path = fixture($parts);
try {
    $html = (new DocPptxPreview())->render($path);
    check(strpos($html, 'First &lt;script&gt;') < strpos($html, 'Last slide'), 'Slides must follow presentation order.');
    check(!str_contains($html, '<script>'), 'Slide text must be HTML escaped.');
    check(str_contains($html, '&bull; First') && str_contains($html, '<br>Second line'), 'Bullets and line breaks must render.');
    check(str_contains($html, '<td><p>Table cell</p></td>'), 'Tables must render.');
    check(str_contains($html, 'src="data:image/png;base64,'), 'Embedded raster images must render.');
    check(str_contains($html, 'Image &quot;description&quot;'), 'Image descriptions must be escaped.');
    check(!str_contains($html, 'https://example.com') && !str_contains($html, 'data:image/svg'), 'External and active images must not render.');
    check(substr_count($html, '<section class="pptx-slide"') === 2, 'Each slide must render once.');
    check(str_contains($html, 'href="#pptx-slide-2"'), 'Slide navigation must render.');
} finally {
    unlink($path);
}

$invalid = $parts;
$invalid['ppt/presentation.xml'] = '<invalid';
expectFailure($invalid, 'Malformed XML must be rejected.');
$invalid['ppt/presentation.xml'] = '<!DOCTYPE x [<!ENTITY secret SYSTEM "file:///etc/passwd">]><x>&secret;</x>';
expectFailure($invalid, 'XML with a doctype must be rejected.');
$invalid = $parts;
unset($invalid['ppt/slides/slide2.xml']);
expectFailure($invalid, 'Missing slide parts must be rejected.');
$invalid = $parts;
$invalid['ppt/presentation.xml'] = str_repeat(' ', 4194305);
expectFailure($invalid, 'Oversized XML parts must be rejected before decompression.');
$invalid = $parts;
$invalid['ppt/_rels/presentation.xml.rels'] = str_replace('slides/slide1.xml', '../../../outside.xml', $invalid['ppt/_rels/presentation.xml.rels']);
expectFailure($invalid, 'Relationships escaping the archive root must be rejected.');

echo "PPTX preview tests passed.\n";
