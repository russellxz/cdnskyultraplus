<?php
header('Content-Type: text/plain; charset=utf-8');
echo "upload_max_filesize = ".ini_get('upload_max_filesize').PHP_EOL;
echo "post_max_size       = ".ini_get('post_max_size').PHP_EOL;
echo "file_uploads        = ".ini_get('file_uploads').PHP_EOL;
echo "memory_limit        = ".ini_get('memory_limit').PHP_EOL;