<?php

namespace Unico\Core\Enums;

/** Raggruppamento documentale delle tipologie di cessione. */
enum EmploymentDocumentGroup: string
{
    case Pensionati = 'pensionati';
    case Privati = 'privati';
    case Pubblici = 'pubblici';
    case Parapubblici = 'parapubblici';
}
