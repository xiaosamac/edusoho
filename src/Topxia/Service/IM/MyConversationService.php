<?php

namespace Topxia\Service\IM;

interface MyConversationService
{
    public function getMyConversation($id);

    public function getMyConversationByNo($no);

    public function findMyConversationsByUserId($userId);

    public function addMyConversation($myConversation);

    public function updateMyConversation($id, $fields);

    public function updateMyConversationByNo($no, $fields);

    public function searchMyConversations($conditions, $orderBy, $start, $limit);

    public function searchMyConversationCount($conditions);
}
