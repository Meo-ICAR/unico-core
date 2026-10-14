<?php

namespace Unico\Core\Enums;

/** Raggruppamento documentale delle tipologie lavorative (cessione, mutui, prestiti). */
enum EmploymentDocumentGroup: string
{
    case Pensionati = 'pensionati';
    case Privati = 'privati';
    case Pubblici = 'pubblici';
    case Parapubblici = 'parapubblici';
    case Autonomi = 'autonomi';
}
