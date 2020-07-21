<?php

namespace Plugin\Api\Entity;

trait NonceTrait
{
    /**
     * @var string|null
     */
    protected $nonce;

    /**
     * Set the nonce.
     *
     * @param string|null $nonce
     */
    public function setNonce($nonce)
    {
        $this->nonce = $nonce;
    }

    /**
     * Get the nonce.
     *
     * @return string|null
     */
    public function getNonce()
    {
        return $this->nonce;
    }

}
