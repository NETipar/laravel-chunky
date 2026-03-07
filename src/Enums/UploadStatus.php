<?php

namespace NETipar\Chunky\Enums;

enum UploadStatus: string
{
    case Pending = 'pending';
    case Assembling = 'assembling';
    case Completed = 'completed';
    case Expired = 'expired';
}
