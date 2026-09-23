<?php
/**
 * Miniaturní náhledy šablon webu pro instalátor a Vzhled → Identita webu.
 *
 * @return array<string, string> klíč => SVG
 */
$svg = fn (string $obsah): string => '<svg viewBox="0 0 240 150" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">' . $obsah . '</svg>';

return [
    'minimal' => $svg(
        '<rect width="240" height="150" fill="#fff"/>'
        . '<rect x="92" y="14" width="56" height="8" rx="1" fill="#1B1B1F"/><rect x="100" y="27" width="40" height="3" fill="#C9CAD1"/>'
        . '<rect x="70" y="38" width="100" height="1" fill="#E6E6EA"/>'
        . '<rect x="70" y="48" width="18" height="3" fill="#2F5BEA"/><rect x="70" y="55" width="100" height="7" rx="1" fill="#1B1B1F"/><rect x="70" y="66" width="86" height="3" fill="#C9CAD1"/>'
        . '<rect x="70" y="76" width="100" height="1" fill="#E6E6EA"/>'
        . '<rect x="70" y="84" width="14" height="3" fill="#2F5BEA"/><rect x="70" y="91" width="72" height="6" rx="1" fill="#1B1B1F"/><rect x="70" y="101" width="66" height="3" fill="#C9CAD1"/><rect x="148" y="84" width="22" height="18" rx="2" fill="#DADCE3"/>'
        . '<rect x="70" y="112" width="100" height="1" fill="#E6E6EA"/>'
        . '<rect x="70" y="120" width="16" height="3" fill="#2F5BEA"/><rect x="70" y="127" width="80" height="6" rx="1" fill="#1B1B1F"/>'
    ),
    // obecný náhled pro vlastní šablony (vestavěné mají svůj)
    'vlastni' => $svg(
        '<rect width="240" height="150" fill="#fff"/>'
        . '<rect x="14" y="12" width="80" height="9" rx="1" fill="#1B1B1F"/><rect y="30" width="240" height="2" fill="#0A2FC4"/>'
        . '<g fill="#DADDE5"><rect x="14" y="44" width="40" height="3"/><rect x="14" y="54" width="34" height="3"/><rect x="14" y="64" width="38" height="3"/><rect x="186" y="44" width="40" height="3"/><rect x="186" y="54" width="36" height="3"/><rect x="186" y="64" width="40" height="3"/></g>'
        . '<rect x="68" y="44" width="70" height="7" fill="#1B1B1F"/><g fill="#C9CCD6"><rect x="68" y="58" width="104" height="3"/><rect x="68" y="66" width="96" height="3"/></g>'
        . '<rect x="68" y="86" width="84" height="7" fill="#1B1B1F"/><g fill="#C9CCD6"><rect x="68" y="100" width="104" height="3"/><rect x="68" y="108" width="90" height="3"/></g>'
    ),
    'classic-newspaper' => $svg(
        '<rect width="240" height="150" fill="#FDFCF9"/>'
        . '<text x="120" y="24" text-anchor="middle" font-family="Georgia,serif" font-size="17" font-weight="bold" fill="#121212">The Daily Magazín</text>'
        . '<rect x="14" y="32" width="212" height="1" fill="#121212"/><rect x="14" y="35" width="212" height="1" fill="#121212"/>'
        . '<rect x="14" y="46" width="120" height="9" fill="#121212"/><rect x="14" y="59" width="96" height="9" fill="#121212"/>'
        . '<g fill="#B9B6AE"><rect x="14" y="76" width="120" height="3"/><rect x="14" y="84" width="112" height="3"/><rect x="14" y="92" width="118" height="3"/></g>'
        . '<rect x="144" y="44" width="1" height="92" fill="#D9D6CE"/>'
        . '<rect x="154" y="46" width="72" height="40" fill="#D9D6CE"/><rect x="154" y="92" width="64" height="6" fill="#121212"/><g fill="#B9B6AE"><rect x="154" y="104" width="72" height="3"/><rect x="154" y="112" width="60" height="3"/></g>'
        . '<rect x="14" y="106" width="120" height="1" fill="#D9D6CE"/><rect x="14" y="114" width="54" height="6" fill="#121212"/><rect x="78" y="114" width="54" height="6" fill="#121212"/>'
    ),
    'modern-magazine' => $svg(
        '<rect width="240" height="150" fill="#fff"/>'
        . '<rect width="240" height="20" fill="#000"/><rect x="12" y="6" width="40" height="8" fill="#fff"/><g fill="#8A8A8A"><rect x="150" y="8" width="20" height="4"/><rect x="178" y="8" width="20" height="4"/><rect x="206" y="8" width="20" height="4"/></g>'
        . '<rect x="12" y="30" width="216" height="62" fill="#1A1A1A"/><rect x="22" y="58" width="130" height="10" fill="#fff"/><rect x="22" y="73" width="96" height="10" fill="#fff"/><rect x="22" y="44" width="30" height="5" fill="#FF2D6F"/>'
        . '<g fill="#E6E6E6"><rect x="12" y="102" width="66" height="28"/><rect x="87" y="102" width="66" height="28"/><rect x="162" y="102" width="66" height="28"/></g>'
        . '<g fill="#000"><rect x="12" y="135" width="56" height="6"/><rect x="87" y="135" width="50" height="6"/><rect x="162" y="135" width="58" height="6"/></g>'
    ),
];
